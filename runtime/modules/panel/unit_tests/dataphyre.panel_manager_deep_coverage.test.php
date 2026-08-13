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
use Dataphyre\Panel\NavigationItem;
use Dataphyre\Panel\PanelCommand;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\PanelThemePreset;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['core'=>true,'panel'=>true],
		'disabled'=>['permission'=>true],
		'core_implicit'=>true,
	]);
}
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
require_once $modulesRoot.'/core/kernel/core_functions.php';
require_once $modulesRoot.'/core/kernel/helper_functions.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; function tracelog(mixed ...$arguments): void {}');
}
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['panel']);

function dp_panel_manager_fresh(): PanelManager {
	PanelManager::flush();
	return PanelManager::instance();
}

/** @param list<array<string,mixed>> $records */
function dp_panel_manager_resource(string $name='orders',array $records=[]): Resource {
	$records=$records ?: [
		['id'=>1,'title'=>'First order','status'=>'pending'],
		['id'=>2,'title'=>'Second order','status'=>'approved'],
	];
	return Resource::make($name)
		->label('Order')
		->pluralLabel('Orders')
		->fields([
			Field::make('title')->required(false),
			Field::make('status')->required(false),
		])
		->queryUsing(static fn(mixed ...$arguments): array=>$records)
		->saveUsing(static fn(array $data,mixed $record=null,string $operation='store'): array=>array_replace(is_array($record) ? $record : [],$data,['operation'=>$operation]))
		->importUsing(static fn(array $rows): array=>['imported'=>array_keys($rows),'failed'=>[]])
		->globalSearchable()
		->globalSearchColumns(['title'])
		->globalSearchUsing(static fn(string $query,PanelRequest $request,Resource $resource,int $limit): array=>[
			['id'=>1,'title'=>'Found '.$query],
			['id'=>2,'title'=>'Also '.$query],
		]);
}

test('panel manager registers every registry kind and resolves themes',static function(Context $t): void {
	PanelManager::flush();
	$manager=PanelManager::instance();
	$t->same($manager,PanelManager::instance());

	$resource=$manager->register(dp_panel_manager_resource('orders'));
	$arrayResource=$manager->register(['name'=>'customers','label'=>'Customer']);
	$t->same('orders',$resource->name());
	$t->same('customers',$arrayResource->name());
	$t->throws(static fn()=>$manager->register(Resource::make(' ')),InvalidArgumentException::class);
	$resources=$manager->registerMany([
		dp_panel_manager_resource('invoices'),
		['name'=>'payments'],
		null,
		'ignored',
	]);
	$t->same(2,count($resources));

	$page=$manager->registerPage(PanelPage::make('reports')->content('Report'));
	$arrayPage=$manager->registerPage(['name'=>'settings','content'=>'Settings']);
	$t->same('reports',$page->name());
	$t->same('settings',$arrayPage->name());
	$t->throws(static fn()=>$manager->registerPage(PanelPage::make(' ')),InvalidArgumentException::class);
	$t->same(2,count($manager->registerPages([
		PanelPage::make('activity'),
		['name'=>'audit'],
		false,
	])));

	$widget=$manager->registerWidget(Widget::make('revenue')->value(10));
	$arrayWidget=$manager->registerWidget(['name'=>'orders_count','value'=>2]);
	$t->same('revenue',$widget->name());
	$t->same('orders_count',$arrayWidget->name());
	$t->throws(static fn()=>$manager->registerWidget(Widget::make(' ')),InvalidArgumentException::class);
	$t->same(2,count($manager->registerWidgets([
		Widget::make('customers_count'),
		['name'=>'alerts_count'],
		new stdClass(),
	])));

	$item=$manager->registerNavigationItem(NavigationItem::make('documentation')->url('/docs'));
	$arrayItem=$manager->registerNavigationItem(['name'=>'support','url'=>'/support']);
	$t->same('documentation',$item->name());
	$t->same('support',$arrayItem->name());
	$t->throws(static fn()=>$manager->registerNavigationItem(NavigationItem::make(' ')),InvalidArgumentException::class);
	$t->same(2,count($manager->registerNavigationItems([
		NavigationItem::make('status')->url('/status'),
		['name'=>'billing','url'=>'/billing'],
		42,
	])));

	$command=$manager->registerCommand(PanelCommand::make('open_reports')->url('/reports'));
	$arrayCommand=$manager->registerCommand(['name'=>'open_settings','url'=>'/settings']);
	$t->same('open_reports',$command->name());
	$t->same('open_settings',$arrayCommand->name());
	$t->throws(static fn()=>$manager->registerCommand(PanelCommand::make(' ')),InvalidArgumentException::class);
	$t->same(2,count($manager->registerCommands([
		PanelCommand::make('open_activity')->url('/activity'),
		['name'=>'open_audit','url'=>'/audit'],
		(object)['name'=>'ignored'],
	])));

	$t->same($resource,$manager->get(' Orders '));
	$t->same($page,$manager->getPage('Reports'));
	$t->isTrue($manager->has('orders'));
	$t->isTrue($manager->hasPage('reports'));
	$t->isFalse($manager->has('missing'));
	$t->isFalse($manager->hasPage('missing'));
	$t->isTrue(count($manager->resources())>=4);
	$t->isTrue(count($manager->pages())>=4);
	$t->isTrue(count($manager->navigationItems())>=4);
	$t->isTrue(count($manager->registeredCommands())>=4);

	$defaultTheme=$manager->theme();
	$t->same($defaultTheme,$manager->theme());
	$t->isTrue($manager->theme(PanelThemePreset::make('operator')) instanceof PanelTheme);
	$t->isTrue($manager->theme('default') instanceof PanelTheme);
	$t->same('unlisted_theme',$manager->theme('unlisted theme')->name());
	$t->same('array_theme',$manager->theme(['name'=>'array theme'])->name());
	$objectTheme=PanelTheme::make('object theme');
	$t->same($objectTheme,$manager->theme($objectTheme));
	$t->same($manager,$manager->authorize(static fn(): bool=>true));

	PanelManager::flush();
	$t->isFalse(PanelManager::instance()===$manager);
})->tag('panel','manager','coverage')->group('framework-coverage');

test('panel manager builds widget search navigation command and manifest state',static function(Context $t): void {
	$manager=dp_panel_manager_fresh();
	$request=PanelRequest::fromArray(['user'=>['id'=>7],'query'=>['q'=>'first']]);
	$manager->registerWidgets([
		Widget::make('late')->label('Zulu')->sort(200)->value(2),
		Widget::make('early')->label('Alpha')->sort(10)->value(1),
	]);
	$statusResource=dp_panel_manager_resource('orders')
		->statusField('status')
		->statusWidgets()
		->statusTransitions([
			['name'=>'approve','from'=>'pending','to'=>'approved','label'=>'Approved','tone'=>'success'],
		]);
	$manager->register($statusResource);
	$manager->register(dp_panel_manager_resource('no_widgets')->policy(['dashboard_widgets'=>false]));
	$states=$manager->widgetStates($request);
	$t->isTrue(count($states)>=3,'status widgets join registered widgets');
	$t->same('early',$states[0]->widget()['name']);
	$t->same(count($states),count($manager->widgets($request)));

	$manager->register(Resource::make('not_searchable'));
	$manager->register(dp_panel_manager_resource('denied_search')->policy(['global_search'=>false]));
	$manager->register(dp_panel_manager_resource('broken_search')->globalSearchUsing(
		static function(): never { throw new RuntimeException('search failed'); }
	));
	$t->same([],$manager->globalSearch('   ',$request,0));
	$search=$manager->globalSearch('needle',$request,50);
	$t->isTrue(count($search)>=2,'global search returns results');
	$t->same(1,count($manager->globalSearch('needle',$request,1)));

	$manager->register(dp_panel_manager_resource('hidden')->hideFromNavigation());
	$manager->register(dp_panel_manager_resource('nav_denied')->policy(['view_any'=>false]));
	$manager->registerPage(PanelPage::make('visible_page')->url('/visible')->content('Visible'));
	$manager->registerPage(PanelPage::make('hidden_page')->hideFromNavigation()->content('Hidden'));
	$manager->registerPage(PanelPage::make('denied_page')->authorize(static fn(): bool=>false)->content('Denied'));
	$manager->registerNavigationItem(NavigationItem::make('custom')->url('/custom'));
	$manager->registerNavigationItem(NavigationItem::make('hidden_custom')->url('/hidden')->hide());
	$navigationState=$manager->navigationState($request,['query'=>'needle']);
	$t->isTrue(count($navigationState->allEntries())>=3,'navigation state has entries');
	$t->isTrue(count($manager->navigation($request))>=3,'navigation manifest has entries');
	$t->isTrue($manager->navigationState()->jsonSerialize()['meta']['resources']>=1,'navigation state reports resources');

	$manager->registerNavigationItem(NavigationItem::make('empty_url')->folderOnly());
	$manager->registerCommand(PanelCommand::make('registered')->url('/registered'));
	$manager->registerCommand(PanelCommand::make('hidden_command')->url('/hidden')->hide());
	$commandState=$manager->commandState($request);
	$t->isTrue(count($commandState->commands())>=4,'command state has base and registry commands');
	$t->isTrue(count($manager->commands($request,'order'))>=1,'command query finds order commands');
	$t->isTrue(is_array($manager->commands($request,'definitely absent phrase')));

	$description=$manager->describe();
	$t->isTrue(count($description['resources'])>=1,'description includes resources');
	$t->isTrue(count($description['global_searchable_resources'])>=1,'description includes searchable resources');
	$t->same(2,count($description['widgets']));
	$t->isTrue(count($description['pages'])>=3,'description includes pages');
	$t->same(null,$description['theme']);
	$t->isTrue(count($description['navigation_items'])>=3,'description includes navigation items');
	$t->isTrue(is_array($description['navigation_state']),'description navigation state serializes');
	$t->isTrue(is_array($description['command_state']),'description command state serializes');

	$guarded=dp_panel_manager_fresh();
	$guarded->register(dp_panel_manager_resource('blocked'));
	$guarded->register(dp_panel_manager_resource('allowed'));
	$guarded->authorize(static fn(string $ability,?Resource $resource): bool=>$resource?->name()!=='blocked');
	$entries=$guarded->navigationState($request)->allEntries();
	$t->same(1,count($entries));
})->tag('panel','manager','coverage')->group('framework-coverage');

test('panel manager composes resource commands across authorization branches',static function(Context $t): void {
	$request=PanelRequest::fromArray(['user'=>(object)['id'=>9]]);
	$manager=dp_panel_manager_fresh();
	$hidden=dp_panel_manager_resource('hidden_commands')->hideFromNavigation();
	$denied=dp_panel_manager_resource('denied_commands')->policy(['view_any'=>false]);
	$createDenied=dp_panel_manager_resource('create_denied')->policy(['create'=>false]);
	$importDenied=dp_panel_manager_resource('import_denied')->policy(['import'=>false]);
	$full=dp_panel_manager_resource('full_commands')->statusTransitions([
		['name'=>'approve','from'=>'pending','to'=>'approved'],
	]);
	$manager->registerMany([$hidden,$denied,$createDenied,$importDenied,$full]);
	$entries=$t->nonPublic($manager)->invoke('commandEntries',$request);
	$names=array_column($entries,'name');
	$t->isTrue(in_array('dashboard',$names,true));
	$t->isTrue(in_array('global_search',$names,true));
	$t->isTrue(in_array('keyboard_shortcuts',$names,true));
	$t->isTrue(in_array('full_commands_create',$names,true));
	$t->isTrue(in_array('full_commands_import',$names,true));
	$t->isTrue(in_array('full_commands_board',$names,true));
	$t->isFalse(in_array('hidden_commands_create',$names,true));
	$t->isFalse(in_array('denied_commands_create',$names,true));
	$t->isFalse(in_array('create_denied_create',$names,true));
	$t->isFalse(in_array('import_denied_import',$names,true));

	$blocked=dp_panel_manager_fresh();
	$blocked->register(dp_panel_manager_resource('manager_denied'));
	$blocked->authorize(static fn(string $ability): bool=>$ability!=='view_any');
	$t->same([],$t->nonPublic($blocked)->invoke('resourceCommandEntries',$blocked->get('manager_denied'),
		$request,));

	PanelContext::run(['resource_imports'=>false],static function()use($t,$full,$request): void {
		$manager=dp_panel_manager_fresh();
		$commands=$t->nonPublic($manager)->invoke('resourceCommandEntries',$full,$request);
		$t->isFalse(in_array('full_commands_import',array_column($commands,'name'),true));
	});
})->tag('panel','manager','coverage')->group('framework-coverage');

test('panel manager resolves private record collection and lookup strategies',static function(Context $t): void {
	$manager=dp_panel_manager_fresh();
	$index=PanelRequest::fromArray(['resource'=>'orders','operation'=>'index','query'=>['page'=>2,'per_page'=>3]]);
	$export=PanelRequest::fromArray(['resource'=>'orders','operation'=>'export']);
	$show=PanelRequest::fromArray(['resource'=>'orders','operation'=>'show','record'=>2]);

	$nullResource=Resource::make('none')->queryUsing(static fn(): mixed=>null);
	$t->same([[],null,false],$t->nonPublic($manager)->invoke('records',$nullResource,$index));
	$arrayResource=dp_panel_manager_resource('arrays');
	$t->same(2,count($t->nonPublic($manager)->invoke('records',$arrayResource,$index)[0]));
	$t->same(2,$t->nonPublic($manager)->invoke('findRecord',$arrayResource,$show)['id']);
	$t->same(null,$t->nonPublic($manager)->invoke('findRecord',$arrayResource,PanelRequest::fromArray(['operation'=>'show'])));
	$t->same(null,$t->nonPublic($manager)->invoke('findRecord',$nullResource,$show));
	$t->same(null,$t->nonPublic($manager)->invoke('findRecord',$arrayResource,PanelRequest::fromArray(['operation'=>'show','record'=>999])));

	$objectRecords=[
		(object)['id'=>3,'title'=>'Object id'],
		(object)['key'=>'slug','title'=>'Object key'],
		'noise',
	];
	$objectResource=dp_panel_manager_resource('objects',$objectRecords);
	$t->same('Object id',$t->nonPublic($manager)->invoke('findRecord',$objectResource,
		PanelRequest::fromArray(['operation'=>'show','record'=>3]),)->title);
	$t->same('Object key',$t->nonPublic($manager)->invoke('findRecord',$objectResource,
		PanelRequest::fromArray(['operation'=>'show','record'=>'slug']),)->title);

	$paginationResult=new class {
		public function total(): int { return 8; }
		public function items(): array { return [['id'=>4]]; }
	};
	$paginateQuery=new class($paginationResult) {
		public function __construct(private object $result){}
		public function paginateRecords(int $page,int $perPage): object { return $this->result; }
	};
	$paginateResource=Resource::make('paginate')->queryUsing(static fn(): object=>$paginateQuery);
	$t->same([[['id'=>4]],8,true],$t->nonPublic($manager)->invoke('records',$paginateResource,$index));

	$totalRecordsResult=new class {
		public function totalRecords(): int { return 7; }
		public function items(): array { return [['id'=>5]]; }
	};
	$paginateQuery2=new class($totalRecordsResult) {
		public function __construct(private object $result){}
		public function paginate(int $page,int $perPage): object { return $this->result; }
	};
	$paginateResource2=Resource::make('paginate_two')->queryUsing(static fn(): object=>$paginateQuery2);
	$t->same([[['id'=>5]],7,true],$t->nonPublic($manager)->invoke('records',$paginateResource2,$index));

	$countResult=new class {
		public function count(): int { return 6; }
	};
	$getRecordsQuery=new class($countResult) {
		public function __construct(private object $result){}
		public function getRecords(): object { return $this->result; }
	};
	$getRecordsResource=Resource::make('get_records')->queryUsing(static fn(): object=>$getRecordsQuery);
	$t->same([[],6,false],$t->nonPublic($manager)->invoke('records',$getRecordsResource,$export,true));

	$getQuery=new class {
		public function get(): array { return [['id'=>6]]; }
	};
	$getResource=Resource::make('get')->queryUsing(static fn(): object=>$getQuery);
	$t->same([[['id'=>6]],null,false],$t->nonPublic($manager)->invoke('records',$getResource,$export,true));
	$emptyQuery=new class {};
	$emptyResource=Resource::make('empty_query')->queryUsing(static fn(): object=>$emptyQuery);
	$t->same([[],null,false],$t->nonPublic($manager)->invoke('records',$emptyResource,$index));

	foreach(['findRecord','find','firstRecord','first'] as $method){
		$query=match($method){
			'findRecord'=>new class { public function findRecord(string $key): array { return ['method'=>'findRecord','key'=>$key]; } },
			'find'=>new class { public function find(string $key): array { return ['method'=>'find','key'=>$key]; } },
			'firstRecord'=>new class { public function firstRecord(): array { return ['method'=>'firstRecord']; } },
			default=>new class { public function first(): array { return ['method'=>'first']; } },
		};
		$resource=Resource::make('lookup_'.$method)->queryUsing(static fn(): object=>$query);
		$result=$t->nonPublic($manager)->invoke('findRecord',$resource,$show);
		$t->same($method,$result['method']);
	}
	$t->same(null,$t->nonPublic($manager)->invoke('findRecord',$emptyResource,$show));

	$t->same($show,$t->nonPublic($manager)->invoke('requestWithResolvedResourceState',$arrayResource,$show));
	$t->isTrue($t->nonPublic($manager)->invoke('requestWithResolvedResourceState',$arrayResource,$index) instanceof PanelRequest);
})->tag('panel','manager','coverage')->group('framework-coverage');

test('panel manager evaluates installed and configured authorizers safely',static function(Context $t): void {
	$request=PanelRequest::fromArray(['user'=>'operator']);
	$resource=dp_panel_manager_resource();
	$manager=dp_panel_manager_fresh();
	$t->isTrue($t->nonPublic($manager)->invoke('canAccess','view',$resource,$request),'open manager authorizes');
	$manager->authorize(static fn(string $ability,?Resource $target,mixed $user): bool=>$ability==='view' && $target?->name()==='orders' && $user==='operator');
	$t->isTrue($t->nonPublic($manager)->invoke('canAccess','view',$resource,$request),'installed manager authorizer allows');
	$t->isFalse($t->nonPublic($manager)->invoke('canAccess','delete',$resource,$request));
	$manager->authorize(static function(): never { throw new RuntimeException('policy failed'); });
	$t->isFalse($t->nonPublic($manager)->invoke('canAccess','view',$resource,$request));

	foreach(['show','edit','update','inline_update','delete','force_delete','duplicate','restore','transition','action','relation','tag','task','note','message','attach','approval'] as $operation){
		$t->isTrue($t->nonPublic(PanelManager::class)->invoke('operationUsesRecordPolicy',$operation),'record policy operation '.$operation);
	}
	$t->isFalse($t->nonPublic(PanelManager::class)->invoke('operationUsesRecordPolicy','index'));

	PanelContext::run(['authorize'=>static fn(string $ability): bool=>$ability==='view'],static function()use($t): void {
		$authorizer=$t->nonPublic(PanelManager::class)->invoke('configuredAuthorizer');
		$t->isTrue($authorizer instanceof Closure,'configured callable becomes closure');
		$t->isTrue($authorizer('view'),'configured callable authorizes');
	});
	PanelContext::run(['authorize'=>false],static function()use($t): void {
		$authorizer=$t->nonPublic(PanelManager::class)->invoke('configuredAuthorizer');
		$t->isTrue($authorizer instanceof Closure,'configured bool becomes closure');
		$t->isFalse($authorizer());
	});
	PanelContext::run(['authorize'=>null,'permission'=>false,'permissions'=>false],static function()use($t): void {
		$t->same(null,$t->nonPublic(PanelManager::class)->invoke('configuredAuthorizer'));
	});
	PanelContext::run(['authorize'=>null,'permission'=>['ability'=>'panel.access']],static function()use($t): void {
		$authorizer=$t->nonPublic(PanelManager::class)->invoke('configuredAuthorizer');
		if(class_exists(\Dataphyre\Permission\PermissionPanel::class,false)){
			$t->isTrue($authorizer instanceof Closure,'available permission module supplies an authorizer');
		}else{
			$t->same(null,$authorizer);
		}
	});

	$moduleConfig=$t->nonPublic(\dataphyre\module_registry::class);
	$frameworkModules=$t->nonPublic(\dataphyre\core::class);
	$originalLoaded=$frameworkModules->readProperty('framework_modules_loaded');
	$enabledLoaded=$originalLoaded;
	unset($enabledLoaded['permission']);
	$moduleConfig->replacePropertyForTest('module_config',[
		'enabled'=>['core'=>true,'panel'=>true,'permission'=>true],
		'disabled'=>[],
	]);
	$frameworkModules->replacePropertyForTest('framework_modules_loaded',$enabledLoaded);
	PanelContext::run(['authorize'=>null,'permission'=>['ability'=>'panel.access']],static function()use($t): void {
		$t->isTrue($t->nonPublic(PanelManager::class)->invoke('configuredAuthorizer') instanceof Closure);
	});
})->tag('panel','manager','coverage')->group('framework-coverage');

test('panel manager render routes dashboard pages resources and operation workflows',static function(Context $t): void {
	$record=['id'=>1,'title'=>'First order','status'=>'pending'];
	$resource=dp_panel_manager_resource('orders')
		->actions([
			Action::make('approve')->handle(static fn(mixed $record,array $data): array=>['approved'=>true,'record'=>$record,'data'=>$data]),
		])
		->relations([['name'=>'items','label'=>'Items']]);
	$manager=dp_panel_manager_fresh();
	$manager->register($resource);
	$page=PanelPage::make('reports')
		->content('Reports content')
		->actions([
			Action::make('refresh')->handle(static fn(): array=>['refreshed'=>true]),
		]);
	$manager->registerPage($page);

	$t->isTrue($manager->render() instanceof PanelPageResult);
	$t->same(404,$manager->render('missing')->status());
	$t->isTrue($manager->render('reports') instanceof PanelPageResult);
	$t->isTrue($manager->render('reports','action',['action'=>'refresh']) instanceof PanelPageResult);

	$deniedPageManager=dp_panel_manager_fresh();
	$deniedPageManager->registerPage(PanelPage::make('denied_page')->content('Denied')->authorize(static fn(): bool=>false));
	$t->same(403,$deniedPageManager->render('denied_page')->status());
	$deniedPageManager->authorize(static fn(): bool=>false);
	$t->same(403,$deniedPageManager->render()->status());
	$t->same(403,$deniedPageManager->render('denied_page')->status());

	$deniedResourceManager=dp_panel_manager_fresh();
	$deniedResourceManager->register($resource);
	$deniedResourceManager->authorize(static fn(): bool=>false);
	$t->same(403,$deniedResourceManager->render('orders')->status());
	$policyManager=dp_panel_manager_fresh();
	$policyManager->register(dp_panel_manager_resource('policy_denied')->policy(['index'=>false]));
	$t->same(403,$policyManager->render('policy_denied')->status());

	$manager=dp_panel_manager_fresh();
	$manager->register($resource);
	$base=[
		'record'=>$record,
		'records'=>[$record],
		'action'=>'approve',
		'relation'=>'items',
		'method'=>'POST',
		'query'=>['selected'=>[1]],
		'input'=>[
			'title'=>'Changed',
			'status'=>'approved',
			'action'=>'approve',
			'transition'=>'approve',
			'selected'=>[1],
			'tag'=>'priority',
			'note'=>'Note text',
			'message'=>'Message text',
			'task'=>'Task text',
		],
	];
	$operations=[
		'create','edit','import','import_template','board','bulk_export','bulk_update',
		'transition','bulk_transition','duplicate','bulk_duplicate','restore','bulk_restore',
		'delete','bulk_delete','force_delete','bulk_force_delete','show','approval','tag','task',
		'note','message','attach','relation','export','index',
	];
	$failures=[];
	foreach($operations as $operation){
		try{
			$result=$manager->render('orders',$operation,$base);
			if(!$result instanceof PanelPageResult){
				$failures[$operation]='not a PanelPageResult';
			}
		}catch(Throwable $throwable){
			$failures[$operation]=$throwable::class.': '.$throwable->getMessage();
		}
	}
	$t->same([],$failures);
	$t->isTrue($manager->render('orders','import',array_replace($base,['method'=>'GET'])) instanceof PanelPageResult);
})->tag('panel','manager','coverage')->group('framework-coverage');

test('panel manager dispatch routes pages resources partials flags and failures',static function(Context $t): void {
	$record=['id'=>1,'title'=>'First order','status'=>'pending'];
	$resource=dp_panel_manager_resource('orders')
		->actions([
			Action::make('approve')->handle(static fn(mixed $record,array $data): array=>['approved'=>true,'record'=>$record,'data'=>$data]),
		])
		->relations([['name'=>'items','label'=>'Items']]);
	$manager=dp_panel_manager_fresh();
	$manager->register($resource);
	$manager->registerPage(PanelPage::make('reports')->content('Reports')->actions([
		Action::make('refresh')->handle(static fn(): array=>['refreshed'=>true]),
	]));

	$t->isTrue($manager->dispatch(PanelRequest::fromArray([])) instanceof PanelPageResult);
	$t->isTrue($manager->dispatch([]) instanceof PanelPageResult);
	$t->globalMap('_GET')->clear();
	$t->globalMap('_POST')->clear();
	$t->globalMap('_FILES')->clear();
	$t->globalMap('_SERVER')->put('REQUEST_METHOD','GET');
	$t->isTrue($manager->dispatch() instanceof PanelPageResult);
	$t->same(404,$manager->dispatch(['resource'=>'missing'])->status());
	$t->isTrue($manager->dispatch(['resource'=>'reports']) instanceof PanelPageResult);
	$t->isTrue($manager->dispatch(['resource'=>'reports','operation'=>'action','action'=>'refresh','method'=>'POST']) instanceof PanelPageResult);

	$pageDenied=dp_panel_manager_fresh();
	$pageDenied->registerPage(PanelPage::make('denied')->content('Denied')->authorize(static fn(): bool=>false));
	$t->same(403,$pageDenied->dispatch(['resource'=>'denied'])->status());
	$pageDenied->authorize(static fn(): bool=>false);
	$t->same(403,$pageDenied->dispatch([])->status());

	$collision=dp_panel_manager_fresh();
	$collision->register(dp_panel_manager_resource('shared'));
	$collision->registerPage(PanelPage::make('shared')->content('Shared page'));
	$t->isTrue($collision->dispatch(['resource'=>'shared']) instanceof PanelPageResult);
	$hiddenCollision=dp_panel_manager_fresh();
	$hiddenCollision->register(dp_panel_manager_resource('shared')->hideFromNavigation());
	$hiddenCollision->registerPage(PanelPage::make('shared')->content('Shared page'));
	$t->isTrue($hiddenCollision->dispatch(['resource'=>'shared']) instanceof PanelPageResult);
	$t->isTrue($hiddenCollision->dispatch(['resource'=>'shared','operation'=>'show','record'=>1]) instanceof PanelPageResult);

	$policyManager=dp_panel_manager_fresh();
	$policyManager->register(dp_panel_manager_resource('policy_denied')->policy(['index'=>false]));
	$t->same(403,$policyManager->dispatch(['resource'=>'policy_denied'])->status());
	PanelContext::run(['resource_exports'=>false],static function()use($t,$resource): void {
		$manager=dp_panel_manager_fresh();
		$manager->register($resource);
		$t->same(403,$manager->dispatch(['resource'=>'orders','operation'=>'export'])->status());
	});
	PanelContext::run(['resource_imports'=>false],static function()use($t,$resource): void {
		$manager=dp_panel_manager_fresh();
		$manager->register($resource);
		$t->same(403,$manager->dispatch(['resource'=>'orders','operation'=>'import'])->status());
	});

	$manager=dp_panel_manager_fresh();
	$manager->register($resource);
	$t->isTrue($manager->dispatch([
		'resource'=>'orders','operation'=>'edit','record'=>1,
		'query'=>['__panel_partial'=>'field_state'],
	]) instanceof PanelPageResult);
	$t->isTrue($manager->dispatch([
		'resource'=>'orders','operation'=>'edit','record'=>1,
		'query'=>['__panel_partial'=>'field_options','field'=>'status'],
	]) instanceof PanelPageResult);

	$base=[
		'resource'=>'orders',
		'record'=>1,
		'action'=>'approve',
		'relation'=>'items',
		'method'=>'POST',
		'query'=>['selected'=>[1]],
		'input'=>[
			'title'=>'Changed','status'=>'approved','action'=>'approve','transition'=>'approve',
			'selected'=>[1],'tag'=>'priority','note'=>'Note text','message'=>'Message text','task'=>'Task text',
		],
	];
	$operations=[
		'create','store','edit','update','inline_update','import','import_template','board','bulk_export',
		'bulk_update','transition','bulk_transition','duplicate','bulk_duplicate','restore','bulk_restore',
		'delete','bulk_delete','force_delete','bulk_force_delete','show','approval','tag','task','note',
		'message','attach','relation','action','export','index',
	];
	$failures=[];
	foreach($operations as $operation){
		try{
			$result=$manager->dispatch(array_replace($base,['operation'=>$operation]));
			if(!$result instanceof PanelPageResult){
				$failures[$operation]='not a PanelPageResult';
			}
		}catch(Throwable $throwable){
			$failures[$operation]=$throwable::class.': '.$throwable->getMessage();
		}
	}
	$t->same([],$failures);
	$t->isTrue($manager->dispatch(array_replace($base,['operation'=>'import','method'=>'GET'])) instanceof PanelPageResult);

	$throwing=dp_panel_manager_fresh();
	$throwing->register(Resource::make('broken')->queryUsing(
		static function(): never { throw new RuntimeException('query failed'); }
	));
	$t->throws(static fn()=>$throwing->dispatch(['resource'=>'broken']),RuntimeException::class);
})->tag('panel','manager','coverage')->group('framework-coverage');
