<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Illuminate\Support {
	if(!class_exists(ServiceProvider::class,false)){
		class ServiceProvider {
			public mixed $app;
			public function __construct(mixed $app=null) {
				$this->app=$app;
			}
		}
	}
}

namespace Illuminate\Support\Facades {
	if(!class_exists(Gate::class,false)){
		final class Gate {
			public static ?\Closure $beforeCallback=null;
			public static function before(callable $callback): void {
				self::$beforeCallback=\Closure::fromCallable($callback);
			}
		}
	}
}

namespace {
	use Dataphyre\Panel\PanelInstance;
	use Dataphyre\Panel\PanelRequest;
	use Dataphyre\Panel\Resource;
	use Dataphyre\Permission\Exceptions\AuthorizationException;
	use Dataphyre\Permission\Laravel\AuthorizePermission;
	use Dataphyre\Permission\Laravel\PermissionServiceProvider;
	use Dataphyre\Permission\Middleware\Authorize;
	use Dataphyre\Permission\Middleware\AuthorizeAny;
	use Dataphyre\Permission\Middleware\AuthorizeAnyWhen;
	use Dataphyre\Permission\Middleware\AuthorizeWhen;
	use Dataphyre\Permission\Permission;
	use Dataphyre\Permission\PermissionCondition;
	use Dataphyre\Permission\PermissionEngine;
	use Dataphyre\Permission\PermissionPanel;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!defined('DATAPHYRE_MODULE_POLICY')){
		define('DATAPHYRE_MODULE_POLICY',[
			'enabled'=>['core'=>true,'permission'=>true,'panel'=>true],
			'disabled'=>[],
			'core_implicit'=>true,
		]);
	}
	if(!defined('DP_PERMISSION_CFG')){
		define('DP_PERMISSION_CFG',[
			'roles'=>[],
			'aliases'=>[],
			'default_roles'=>[],
			'super_permissions'=>['*'],
			'subject'=>[
				'permission_keys'=>['permissions'],
				'role_keys'=>['roles'],
			],
			'storage'=>['auto_hydrate'=>false],
			'panel'=>[
				'permission_prefix'=>'panel',
				'resource_prefix'=>'',
				'allow_guest_pages'=>[],
				'super_permission'=>'panel.*',
			],
		]);
	}
	require_once __DIR__.'/permission_coverage_helpers.php';
	$dpPermissionIntegrationModulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
	require_once $dpPermissionIntegrationModulesRoot.'/core/kernel/autoloader.php';
	\dataphyre\autoloader::register($dpPermissionIntegrationModulesRoot);
	\dataphyre\autoloader::register_framework_modules(['permission','panel']);

	final class DpPermissionIntegrationRequest {
		public function __construct(private mixed $subject,private mixed $route='orders.show') {}
		public function user(): mixed { return $this->subject; }
		public function route(): mixed { return $this->route; }
	}

	final class DpPermissionIntegrationApplication {
		/** @var array<string,callable> */
		public array $singletons=[];
		public function singleton(string $name,callable $factory): void {
			$this->singletons[$name]=$factory;
		}
	}

	final class DpPermissionAbortException extends RuntimeException {}

	test('permission middleware adapters cover request shapes parsing conditions denials and downstream handling',static function(Context $t): void {
		Permission::flush();
		PermissionCondition::define('owner',static fn(mixed $subject,array $context): bool=>(string)($subject['id'] ?? '')===(string)($context['owner_id'] ?? ''));
		PermissionCondition::define('always',static fn(): bool=>true);
		$subject=['id'=>7,'permissions'=>['orders.view'],'roles'=>[]];
		$objectRequest=new DpPermissionIntegrationRequest($subject);
		$arrayRequest=['user'=>$subject,'owner_id'=>7];
		$next=static fn(mixed $request): array=>['next'=>$request];

		$t->same($objectRequest,(new Authorize())->handle($objectRequest,$next,'orders.view')['next']);
		$t->same($arrayRequest,(new AuthorizeAny())->handle($arrayRequest,$next,'missing','orders.view')['next']);
		$t->same($arrayRequest,(new AuthorizeWhen())->handle($arrayRequest,$next,' orders.view | owner ')['next']);
		$t->same($arrayRequest,(new AuthorizeAnyWhen())->handle($arrayRequest,$next,'missing, orders.view|owner')['next']);
		$t->same($objectRequest,(new AuthorizeAnyWhen())->handle($objectRequest,$next,'orders.view|always')['next']);

		foreach([Authorize::class,AuthorizeAny::class,AuthorizeWhen::class,AuthorizeAnyWhen::class] as $middleware){
			$middlewareInternals=$t->nonPublic($middleware);
			$t->same($subject,$middlewareInternals->invoke('user',$objectRequest));
			$t->same($subject,$middlewareInternals->invoke('user',$arrayRequest));
			$t->same(null,$middlewareInternals->invoke('user','request-without-user'));
		}
		$whenInternals=$t->nonPublic(AuthorizeWhen::class);
		$anyWhenInternals=$t->nonPublic(AuthorizeAnyWhen::class);
		$t->same($arrayRequest+['request'=>$arrayRequest],$whenInternals->invoke('context',$arrayRequest));
		$t->same(['request'=>$objectRequest],$whenInternals->invoke('context',$objectRequest));
		$t->same($arrayRequest+['request'=>$arrayRequest],$anyWhenInternals->invoke('context',$arrayRequest));
		$t->same(['request'=>$objectRequest],$anyWhenInternals->invoke('context',$objectRequest));
		$t->same([['orders.view','orders.edit'],['owner','always']],$whenInternals->invoke(
			'parse',[' orders.view ','orders.edit|owner, always ']
		));
		$t->same([['orders.view'],[]],$anyWhenInternals->invoke('parse',['orders.view']));

		$denied=['user'=>$subject,'owner_id'=>99,'tenant'=>'north'];
		$exception=$t->throws(
			static fn()=>(new AuthorizeAnyWhen())->handle($denied,static fn()=>null,'missing,orders.view|owner'),
			AuthorizationException::class
		);
		$t->contains('orders.view',$exception->permissions());
		$t->same(['owner'],$exception->context()['conditions']);
		Permission::flush();
	})->tag('permission','middleware','coverage')->group('framework-coverage');

	test('permission Laravel middleware and service provider cover allow throw abort singleton and Gate integration',static function(Context $t): void {
		Permission::flush();
		$subject=['id'=>9,'permissions'=>['orders.view'],'roles'=>[]];
		$request=new DpPermissionIntegrationRequest($subject,['resource'=>'orders']);
		$middleware=new AuthorizePermission();
		$t->same($request,$middleware->handle($request,static fn(mixed $value): mixed=>$value,'orders.view'));
		$t->throws(
			static fn()=>$middleware->handle(new stdClass(),static fn()=>null,'orders.view'),
			AuthorizationException::class
		);
		if(!function_exists('abort')){
			function abort(int $status): never {
				throw new DpPermissionAbortException('abort '.$status);
			}
		}
		$t->throws(
			static fn()=>$middleware->handle(new stdClass(),static fn()=>null,'orders.view'),
			DpPermissionAbortException::class
		);

		$app=new DpPermissionIntegrationApplication();
		$provider=new PermissionServiceProvider($app);
		$provider->register();
		$t->isTrue(isset($app->singletons['dataphyre.permission']));
		$t->instanceOf(PermissionEngine::class,($app->singletons['dataphyre.permission'])());
		$provider->boot();
		$t->instanceOf(Closure::class,\Illuminate\Support\Facades\Gate::$beforeCallback);
		$before=\Illuminate\Support\Facades\Gate::$beforeCallback;
		$t->same(true,$before($subject,'orders.view',['tenant'=>'north']));
		$t->same(null,$before($subject,'orders.edit','single-argument'));
		Permission::flush();
	})->tag('permission','laravel','coverage')->group('framework-coverage');

	test('permission panel residual callbacks render catalog delete resources and derive action names',static function(Context $t): void {
		\Dataphyre\Permission\dp_permission_sql_reset($t);
		Permission::flush();
		$panel=PanelInstance::make('permission-residual');
		PermissionPanel::registerAdminResources($panel,['catalog_page'=>true]);
		$page=$panel->manager()->pages()['permission_catalog'];
		$content=$page->render(PanelRequest::fromArray(['resource'=>'permission_catalog']),$panel->manager());
		$t->same('Permission Catalog',$content['title']);
		$t->contains('Permission Catalog',$content['content']);

		$roles=PermissionPanel::rolesResource($panel);
		$t->same(['deleted'=>true,'message'=>'Role deleted.'],$roles->deleteRecord(['name'=>'missing-role']));
		$assignments=PermissionPanel::assignmentsResource($panel);
		$t->same(['deleted'=>true,'message'=>'Assignment deleted.'],$assignments->deleteRecord(['id'=>'missing-assignment']));

		$actionRequest=PanelRequest::fromArray([
			'method'=>'POST','resource'=>'orders','operation'=>'action','action'=>'Approve Order',
		]);
		$t->same('panel.orders.action.approve_order',PermissionPanel::permissionFor('',Resource::make('orders'),$actionRequest));
		Permission::flush();
	})->tag('permission','panel','coverage')->group('framework-coverage');

	test('permission panel registration and authorizer distinguish guest dashboard and protected resource requests',static function(Context $t): void {
		Permission::flush();
		$panel=PanelInstance::make('permission-authorizer');
		$t->same($panel,PermissionPanel::register($panel,[
			'allow_guest_pages'=>['login'],
			'super_permission'=>'panel.super',
		]));
		$config=$t->nonPublic($panel)->readProperty('config');
		$t->same('panel.super',$config['permission']['super_permission']);

		$authorizer=PermissionPanel::authorizer([
			'allow_guest_pages'=>['login'],
			'super_permission'=>'panel.super',
		]);
		$t->isTrue($authorizer('view',null,null,PanelRequest::fromArray(['resource'=>'login'])));
		$t->isTrue($authorizer('view',null,[
			'id'=>71,
			'permissions'=>['panel.dashboard.view'],
			'roles'=>[],
		],PanelRequest::fromArray(['resource'=>null,'operation'=>'view'])));
		$t->isTrue($authorizer('view',Resource::make('orders'),[
			'id'=>72,
			'permissions'=>['panel.orders.view'],
			'roles'=>[],
		],PanelRequest::fromArray([
			'resource'=>'orders','operation'=>'view','query'=>['panel'=>'admin'],'tenant'=>'north','record'=>'9',
		])));
		Permission::flush();
	})->tag('permission','panel','authorizer','exact-coverage')->group('framework-coverage');

	test('permission panel relation naming derives read and write operations from request method',static function(Context $t): void {
		$get=PanelRequest::fromArray([
			'method'=>'GET','resource'=>'orders','operation'=>'relation','relation'=>'items',
		]);
		$post=PanelRequest::fromArray([
			'method'=>'POST','resource'=>'orders','operation'=>'relation','relation'=>'items',
		]);
		$explicit=PanelRequest::fromArray([
			'method'=>'GET','resource'=>'orders','operation'=>'show','relation'=>'items',
		]);
		$t->same('panel.orders.relation.items.view',PermissionPanel::permissionFor('relation',null,$get));
		$t->same('panel.orders.relation.items.update',PermissionPanel::permissionFor('relation',null,$post));
		$t->same('panel.orders.relation.items.view',PermissionPanel::permissionFor('view',null,$explicit));
	})->tag('permission','panel','relations','exact-coverage')->group('framework-coverage');
}
