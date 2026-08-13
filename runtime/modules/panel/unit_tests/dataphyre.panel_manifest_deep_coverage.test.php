<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelCommand;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelManifest;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',['enabled'=>['core'=>true,'panel'=>true,'permission'=>true],'disabled'=>[],'core_implicit'=>true]);
}
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['panel']);

function dp_panel_manifest_permission_stub(): void {
	if(class_exists('Dataphyre\\Permission\\Permission',false)){
		return;
	}
	\Dataphyre\Test\define_test_symbols('namespace Dataphyre\\Permission;
		final class Permission {
			public static bool $catalogThrows=false;
			public static bool $snapshotThrows=false;
			public static array $catalog=[];
			public static array $snapshot=[];
			public static function panel_catalog(mixed $source,array $options=[]): array {
				if(self::$catalogThrows){ throw new \\RuntimeException("catalog failed"); }
				return self::$catalog;
			}
			public static function snapshot(mixed $subject,array $permissions,array $context=[],array $options=[]): array {
				if(self::$snapshotThrows){ throw new \\RuntimeException("snapshot failed"); }
				return self::$snapshot;
			}
		}');
}

function dp_panel_manifest_enable_permission_policy(Context $t): void {
	$modulesRoot=dirname(__DIR__,2);
	require_once $modulesRoot.'/core/kernel/core_functions.php';
	require_once $modulesRoot.'/core/kernel/helper_functions.php';
	require_once $modulesRoot.'/core/kernel/module_registry.php';
	$t->nonPublic(\dataphyre\module_registry::class)->writeProperty('module_config',[
		'enabled'=>['core'=>true,'panel'=>true,'permission'=>true],
		'disabled'=>[],
	]);
	$t->nonPublic(\dataphyre\core::class)->writeProperty('framework_modules_loaded',[]);
}

suite('Panel manifest composition contracts')
	->contract('panel.manifest-composition', 1)
	->layer('contract')
	->risk('high')
	->watches('module:panel', 'module:permission')
	->through('manifest', 'registry', 'manager', 'permission-catalog')
	->isolation('case')
	->tag('panel', 'manifest')
	->group('framework-coverage');

test('panel manifest composes a serialized surface and child manifests',static function(Context $t): void {
	$request=PanelRequest::fromArray([
		'resource'=>'orders',
		'operation'=>'edit',
		'record'=>'42',
		'action'=>'review',
		'relation'=>'items',
		'tenant'=>'CA',
		'user'=>['id'=>7],
	]);
	$description=[
		'name'=>'catalog',
		'label'=>'Catalog Panel',
		'resources'=>[
			'invalid resource',
			[
				'name'=>'orders',
				'label'=>'Orders',
				'fields'=>[['name'=>'number','type'=>'text']],
				'columns'=>[['name'=>'number','type'=>'text']],
				'actions'=>[['name'=>'review','label'=>'Review']],
				'relations'=>[['name'=>'items']],
				'global_searchable'=>true,
			],
		],
		'pages'=>[
			'invalid page',
			['name'=>'reports','label'=>'Reports','content'=>'Report body'],
		],
		'widgets'=>[
			'invalid widget',
			['name'=>'revenue','type'=>'stat','label'=>'Revenue','value'=>125],
		],
		'widget_runtime'=>[
			'type'=>'panel_widget_runtime_registry','contract_version'=>1,
			'adapters'=>['fixture'=>['owner'=>'test','manifest'=>['name'=>'fixture']]],
			'capabilities'=>['instance_scoped'=>true,'persistent_binding_keys'=>true,'reactor_bridge'=>false],
		],
		'data_sources'=>[
			'type'=>'panel_data_source_registry','contract_version'=>1,'count'=>1,'revision'=>3,
			'sources'=>['remote'=>['owner'=>'fixture','capabilities'=>['filters'=>true,'endpointUrl'=>'https://private.example.test/v1'],'meta'=>['headers'=>['Authorization'=>'secret'],'transport'=>'PrivateTransport']]],
			'capabilities'=>['contributor_layers'=>true,'transactional_checkpoint'=>true,'live_adapter_code_run_by_manifest'=>false],
			'attachment'=>['configured'=>true],
			'private_key'=>'must-redact',
		],
		'data_surfaces'=>[
			'type'=>'panel_data_surface_registry','version'=>1,'count'=>1,'revision'=>2,
			'definitions'=>['orders'=>['id'=>'orders','source'=>'orders']],
			'capabilities'=>['contributor_layers'=>true,'transactional_checkpoint'=>true],
			'attachment'=>['configured'=>true],
			'private_key'=>'must-redact',
		],
		'studio_editor'=>[
			'type'=>'panel_studio_editor','version'=>1,
			'integration'=>['routes_registered'=>false,'host_transport_required'=>true],
			'renderer'=>['contracts'=>['ssr_no_js'=>true]],
			'csrf_token'=>'must-redact',
		],
		'platform'=>[
			'type'=>'panel_platform_manifest','version'=>1,
			'counts'=>['domains'=>0,'configured'=>0,'ready'=>0,'services'=>0],
			'attachment'=>['configured'=>false],
		],
		'navigation'=>[
			['name'=>'orders','label'=>'Orders','url'=>'/orders','group'=>'Commerce'],
		],
		'commands'=>[
			'invalid command',
			['name'=>'refresh','label'=>'Refresh','group'=>'Operations','url'=>'/refresh'],
		],
		'theme'=>[
			'name'=>'manifest_theme',
			'brand'=>['name'=>'Manifest Brand'],
			'colors'=>['primary'=>'#2255aa'],
		],
		'plugins'=>[
			'invalid plugin',
			['id'=>'audit','name'=>'Audit tools','version'=>'1.2.3'],
		],
	];
	$meta=[
		'route_prefix'=>'/backoffice',
		'route_options'=>['middleware'=>['auth']],
		'route_name'=>'backoffice.panel',
		'navigation_layout'=>'rail',
		'navigation_mode'=>'fixed',
		'export'=>'serialized',
	];
	$manifest=PanelContext::run([
		'render_hooks'=>['footer.before'=>'copyright'],
		'footer'=>'Footer',
		'header_mode'=>'compact',
		'footer_mode'=>'compact',
		'navigation_sticky'=>true,
		'header_sticky'=>true,
		'footer_sticky'=>true,
	],static fn(): array=>PanelManifest::from($description,$request,$meta)->toArray());

	$t->same('panel_manifest',$manifest['type']);
	$t->same('catalog',$manifest['name']);
	$t->same('Catalog Panel',$manifest['label']);
	$t->isTrue(isset($manifest['resources']['orders']));
	$t->isTrue(isset($manifest['pages']['reports']));
	$t->isTrue(isset($manifest['widgets']['revenue']));
	$t->same(1,$manifest['capabilities']['widget_runtime']['adapters']);
	$t->isTrue($manifest['capabilities']['widget_runtime']['persistent_binding_keys']);
	$t->same(1,$manifest['capabilities']['data_sources']['sources']);
	$t->isTrue($manifest['capabilities']['data_sources']['configured']);
	$t->isFalse($manifest['capabilities']['data_sources']['live_adapter_code_run_by_manifest']);
	$t->same('[REDACTED]',$manifest['data_sources']['private_key']);
	$t->same('[REDACTED]',$manifest['data_sources']['sources']['remote']['capabilities']['endpointUrl']);
	$t->same('[REDACTED]',$manifest['data_sources']['sources']['remote']['meta']['headers']);
	$t->notContains('private.example.test',json_encode($manifest['data_sources'],JSON_THROW_ON_ERROR));
	$t->same(1,$manifest['capabilities']['data_surfaces']['definitions']);
	$t->isTrue($manifest['capabilities']['data_surfaces']['configured']);
	$t->same('[REDACTED]',$manifest['data_surfaces']['private_key']);
	$t->isTrue($manifest['capabilities']['studio_editor']['available']);
	$t->isTrue($manifest['capabilities']['studio_editor']['ssr_no_js']);
	$t->same('[REDACTED]',$manifest['studio_editor']['csrf_token']);
	$t->isTrue(isset($manifest['commands']['refresh']));
	$t->isTrue(isset($manifest['plugins']['audit']));
	$t->isTrue($manifest['routes']['mounted']);
	$t->same('/backoffice',$manifest['routes']['prefix']);
	$t->same('serialized',$manifest['meta']['export']);
	$t->isTrue($manifest['shell']['chrome']['footer_configured']);
})->tag('panel','manifest','coverage')->group('framework-coverage');

test('panel manifest composes a live panel instance',static function(Context $t): void {
	$manager=new PanelManager();
	$panel=new PanelInstance('operations',$manager,[
		'panel_label'=>'Operations',
		'permission'=>false,
		'footer_html'=>'Live footer',
	]);
	$panel->theme([
		'name'=>'operations_theme',
		'brand'=>['name'=>'Operations Brand'],
	]);
	$panel->register(Resource::make('orders')
		->label('Orders')
		->fields([['name'=>'number','type'=>'text']])
		->columns([['name'=>'number','type'=>'text']])
		->queryUsing(static fn(): array=>[['id'=>1,'number'=>'SO-1']]));
	$panel->registerPage(PanelPage::make('reports')->label('Reports')->content('Reports'));
	$panel->registerWidget(Widget::make('activity','stat')->label('Activity')->value(4));
	$panel->registerNavigationItem(['name'=>'orders','label'=>'Orders','url'=>'/orders']);
	$panel->registerCommand(PanelCommand::make('refresh')->label('Refresh')->url('/refresh'));

	$request=PanelRequest::fromArray(['resource'=>'orders','operation'=>'index','tenant'=>'north']);
	$manifest=$panel->panelManifest($request,[
		'mount_prefix'=>'/ops',
		'route_name'=>'ops.panel',
	]);

	$t->same('operations',$manifest['name']);
	$t->same('Operations Brand',$manifest['label']);
	$t->isTrue(isset($manifest['resources']['orders']));
	$t->isTrue(isset($manifest['pages']['reports']));
	$t->isTrue(isset($manifest['widgets']['activity']));
	$t->same('panel_widget_runtime_registry',$manifest['widget_runtime']['type']);
	$t->isTrue($manifest['capabilities']['widget_runtime']['instance_scoped']);
	$t->isTrue(isset($manifest['commands']['refresh']));
	$t->same('Operations Brand',$manifest['theme']['active']['brand']['name']);
	$t->isTrue($manifest['routes']['mounted']);
	$t->same('ops.panel',$manifest['routes']['route_names']['page']);
	$t->same('north',$manifest['tenant']['current']);
})->tag('panel','manifest','coverage')->group('framework-coverage');

test('panel manifest supports manager global and fallback branches',static function(Context $t): void {
	$manager=new PanelManager();
	$manager->register(['name'=>'products','fields'=>[['name'=>'name']],'columns'=>[['name'=>'name']]]);
	$manager->registerPage(['name'=>'dashboard','label'=>'Dashboard','content'=>'Dashboard']);
	$manager->theme(['name'=>'manager_theme','brand'=>['name'=>'Manager Brand']]);
	$manifest=PanelManifest::from($manager,PanelRequest::fromArray([]),['label'=>'Manager Panel'])->toArray();

	$t->same('default',$manifest['name']);
	$t->same('Manager Panel',$manifest['label']);
	$t->isTrue(isset($manifest['resources']['products']));
	$t->isTrue(isset($manifest['pages']['dashboard']));
	$t->isFalse($manifest['routes']['mounted']);

	$managerBuilder=PanelManifest::from($manager);
	$t->same('manager_theme',$t->nonPublic($managerBuilder)->invoke('description')['theme']['name']);
	$t->isTrue(is_array($t->nonPublic(PanelManifest::from(null))->invoke('description')));
	$t->isTrue(count($t->nonPublic(PanelManifest::from(new PanelManager()))->invoke('commandManifests',[]))>0);
	$t->same('Panel',$t->nonPublic(PanelManifest::from([]))->invoke('panelLabel'));
	$fallback=PanelManifest::from(['data_sources'=>['type'=>'invalid'],'data_surfaces'=>['type'=>'invalid']])->toArray();
	$t->isFalse($fallback['capabilities']['data_sources']['configured']);
	$t->same(0,$fallback['data_sources']['count']);
	$t->isFalse($fallback['capabilities']['data_surfaces']['configured']);
	$t->same(0,$fallback['data_surfaces']['count']);

	$emptyInstance=new PanelInstance('',new PanelManager());
	$t->same('Panel',$t->nonPublic(PanelManifest::from($emptyInstance))->invoke('panelLabel'));
})->tag('panel','manifest','coverage')->group('framework-coverage');

test('panel manifest permission integration auto loads and snapshots',static function(Context $t): void {
	dp_panel_manifest_enable_permission_policy($t);
	$options=[
		'permission_prefix'=>'console',
		'resource_prefix'=>'admin',
		'super_permission'=>'console.*',
		'allow_guest_pages'=>['login'],
		'manifest_decisions'=>true,
		'manifest_decision_explain'=>true,
	];
	$panel=new PanelInstance('secure',new PanelManager(),['permission'=>$options]);
	$panel->register(Resource::make('orders')->fields([['name'=>'number']])->columns([['name'=>'number']]));
	$request=PanelRequest::fromArray([
		'resource'=>'orders',
		'operation'=>'index',
		'user'=>['id'=>12,'permissions'=>['console.*']],
		'tenant'=>'west',
	]);
	$t->isFalse(class_exists('Dataphyre\\Permission\\Permission',false));
	$builder=PanelManifest::from($panel,$request);
	$permission=PanelContext::run(['permission'=>$options],static fn(): array=>$t->nonPublic($builder)->invoke('permissionManifest',['orders'=>[]]));

	$t->isTrue($permission['available']);
	$t->isTrue($permission['configured']);
	$t->same('console',$permission['prefix']);
	$t->same('admin',$permission['resource_prefix']);
	$t->isTrue($permission['catalog_count']>0);
	$t->isTrue($permission['decision_snapshot']['included']);
	$t->same('secure',$permission['decision_snapshot']['context']['panel']);
	$t->same('orders',$permission['decision_snapshot']['context']['resource']);
})->tag('panel','manifest','permission','coverage')->group('framework-coverage');

test('panel manifest captures permission catalog failures',static function(Context $t): void {
	dp_panel_manifest_permission_stub();
	\Dataphyre\Permission\Permission::$catalogThrows=true;
	$builder=PanelManifest::from(new PanelInstance('broken',new PanelManager()),PanelRequest::fromArray([]));
	$permission=PanelContext::run(['permission'=>['manifest_decisions'=>true]],static fn(): array=>$t->nonPublic($builder)->invoke('permissionManifest',[]));

	$t->same(0,$permission['catalog_count']);
	$t->same('disabled',$permission['decision_snapshot']['reason']);
})->tag('panel','manifest','permission','coverage')->group('framework-coverage');

test('panel manifest normalizes permission snapshots and errors',static function(Context $t): void {
	dp_panel_manifest_permission_stub();
	\Dataphyre\Permission\Permission::$catalog=[
		['permission'=>'panel.orders.view','type'=>'resource'],
		['permission'=>'','type'=>'action'],
		['permission'=>'panel.orders.action.review','type'=>'action'],
		['permission'=>'panel.orders.relation.items.view','type'=>'relation'],
	];
	\Dataphyre\Permission\Permission::$snapshot=[
		'subject_id'=>9,
		'roles'=>['operator'],
		'rules'=>['panel.*'],
		'allowed'=>['panel.orders.view'],
		'denied'=>['panel.orders.action.review'],
		'decisions'=>['panel.orders.view'=>true],
		'explain'=>['source'=>'stub'],
	];
	$request=PanelRequest::fromArray([
		'resource'=>'orders','operation'=>'edit','record'=>'5','action'=>'review','relation'=>'items',
		'tenant'=>'CA','user'=>['id'=>9],
	]);
	$builder=PanelManifest::from(new PanelInstance('secure',new PanelManager()),$request,['name'=>'fallback']);
	$options=['manifest_decisions'=>true,'manifest_decision_explain'=>true,'permission_prefix'=>'panel'];
	$permission=PanelContext::run(['permission'=>$options],static fn(): array=>$t->nonPublic($builder)->invoke('permissionManifest',['orders'=>[]]));

	$t->same(4,$permission['catalog_count']);
	$t->same(3,count($permission['permissions']));
	$t->same(2,$permission['counts']['by_type']['action']);
	$t->isTrue($permission['decision_snapshot']['included']);
	$t->same(9,$permission['decision_snapshot']['subject_id']);
	$t->same(1,$permission['decision_snapshot']['counts']['allowed']);
	$t->same('items',$permission['decision_snapshot']['context']['relation']);

	\Dataphyre\Permission\Permission::$snapshotThrows=true;
	$error=$t->nonPublic($builder)->invoke('permissionDecisionSnapshot',['panel.orders.view'],$options);
	$t->isFalse($error['included']);
	$t->same('snapshot_error',$error['reason']);
	$t->same('Permission decision snapshot is unavailable.',$error['message']);
})->tag('panel','manifest','permission','coverage')->group('framework-coverage');

test('panel manifest covers route shell capability and legacy normalizers',static function(Context $t): void {
	$builder=PanelManifest::from([],PanelRequest::fromArray([]),[
		'panel_mount_prefix'=>'/panel',
		'name'=>'surface',
		'route_options'=>['methods'=>['GET']],
		'route_name'=>'surface.panel',
	]);
	$route=$t->nonPublic($builder)->invoke('routeManifest');
	$t->isTrue($route['mounted']);
	$t->same('surface.panel',$route['route_names']['page']);
	$unmounted=$t->nonPublic(PanelManifest::from([],null,['route_prefix'=>'  ','name'=>'plain']))->invoke('routeManifest');
	$t->isFalse($unmounted['mounted']);
	$t->same('plain',$unmounted['surface']);

	$navigation=['counts'=>['groups'=>2,'items'=>3]];
	$commands=[
		'open'=>['group'=>'Files','visibility'=>['visible_lazy'=>true]],
		'sync'=>['group'=>'Operations','target'=>['url_lazy'=>true]],
		'close'=>['group'=>'Files'],
	];
	$theme=['active'=>['name'=>'dark','brand'=>['name'=>'Brand']],'capabilities'=>['dark_mode'=>true]];
	$shell=PanelContext::run([
		'render_hooks'=>['header'=>'Header'],
		'footer_html'=>static fn(): string=>'Footer',
	],static fn(): array=>$t->nonPublic(PanelManifest::class)->invoke('shellManifest',['widgets'=>[['name'=>'one'],['name'=>'two']]],$navigation,$commands,$theme,));
	$t->same(3,$shell['commands']['total']);
	$t->same(2,$shell['commands']['groups']);
	$t->same(2,$shell['commands']['lazy']);
	$t->same(2,$shell['widgets']);
	$t->isTrue($shell['chrome']['footer_configured']);

	$capabilities=$t->nonPublic(PanelManifest::class)->invoke('capabilities',[
			'orders'=>[
				'operations'=>['writes'=>true],
				'tenant'=>['scoped'=>true],
				'search'=>['global_searchable'=>true],
				'capabilities'=>['relations'=>['total'=>2]],
			],
			'logs'=>[],
		],
		[
			'reports'=>['rendering'=>['custom_renderer'=>true],'capabilities'=>['tables'=>['total'=>2]]],
			'help'=>[],
		],
		[
			'sales'=>['data'=>['lazy'=>true],'capabilities'=>['chart'=>['enabled'=>true]]],
			'plain'=>[],
		],
		$navigation,
		$commands,
		$theme,
		['audit'=>[]],
		['capabilities'=>['resources'=>['total'=>1]]],
		[
			'enabled'=>true,'available'=>true,'catalog_count'=>4,
			'counts'=>['by_type'=>['resource'=>2,'action'=>1,'relation'=>1]],
		],);
	$t->same(2,$capabilities['resources']['total']);
	$t->same(1,$capabilities['resources']['writable']);
	$t->same(2,$capabilities['resources']['relations']);
	$t->same(2,$capabilities['pages']['tables']);
	$t->same(1,$capabilities['widgets']['lazy']);
	$t->same(1,$capabilities['widgets']['charts']);
	$t->same(2,$capabilities['commands']['groups']);
	$t->same(1,$capabilities['permission']['action_permissions']);

	$actions=$t->nonPublic(PanelManifest::class)->invoke('actionDefinitions',[
		'invalid',
		[
			'name'=>'review_order','type'=>'modal','tone'=>'warning','modal'=>true,'bulk'=>true,
			'fields'=>['fields'=>[['name'=>'reason'],['name'=>'note']]],
			'effects'=>['refresh'=>true],
		],
		[],
	]);
	$t->same('Review Order',$actions['review_order']['label']);
	$t->same(2,$actions['review_order']['fields']);
	$t->same('Action 2',$actions['action_2']['label']);

	$widgets=$t->nonPublic(PanelManifest::class)->invoke('widgetDefinitions',['invalid',['name'=>'legacy','type'=>'stat','value'=>5]]);
	$t->isTrue(isset($widgets['legacy']));
	$t->same('Panel',$t->nonPublic(PanelManifest::class)->invoke('humanize',' ._- '));
	$t->same('Order Items',$t->nonPublic(PanelManifest::class)->invoke('humanize','order_items'));
})->tag('panel','manifest','coverage')->group('framework-coverage');
