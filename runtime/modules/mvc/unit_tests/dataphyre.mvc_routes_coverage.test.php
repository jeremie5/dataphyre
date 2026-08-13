<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\RouteCollection;
use Dataphyre\Mvc\RouteDefinition;
use Dataphyre\Mvc\SignedUrl;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'routing', 'mvc']);

test('mvc routes expose every verb group macro URL and compilation contract', static function(Context $t): void {
	$app=new MvcApplication('coverage', [
		'controllers'=>['namespace'=>'Coverage\\Controllers'],
		'models'=>'Coverage\\Models',
		'views'=>['path'=>'/tmp/views/'],
		'signed_url_secret'=>'route-secret',
		'route_defaults'=>['locale'=>'en'],
		'route_patterns'=>['id'=>'[0-9]+'],
	]);
	$routes=$app->routes();
	$t->same('coverage', $app->name());
	$t->same('Coverage\\Controllers', $app->controllerNamespace());
	$t->same('Coverage\\Models', $app->modelNamespace());
	$t->same('/tmp/views', $app->viewPath());
	$t->same('fallback', $app->config('missing', 'fallback'));
	$t->same(null, $app->manifestCacheFile());
	$t->isFalse($app->manifestCacheEnabled());

	RouteCollection::flushMacros();
	RouteCollection::macro('healthRoute', function(string $path): RouteDefinition {
		return $this->get($path, static fn(): string=>'healthy', ['name'=>'health']);
	});
	$t->isTrue(RouteCollection::hasMacro('healthRoute'));
	$t->same('/health', $routes->healthRoute('/health')->path());
	$t->throws(static fn()=>$routes->missingMacro(), BadMethodCallException::class);

	$routes->get('/get', static fn()=>1, ['name'=>'get']);
	$routes->post('/post', static fn()=>2);
	$routes->head('/head', static fn()=>3);
	$routes->put('/put', static fn()=>4);
	$routes->patch('/patch', static fn()=>5);
	$routes->delete('/delete', static fn()=>6);
	$routes->options('/options', static fn()=>7);
	$routes->any('/any', static fn()=>8);
	$routes->view('/view', 'welcome', ['name'=>'Ada']);
	$routes->redirect('/redirect', '/get', 301);
	$routes->redirectToRoute('/redirect-route', 'user.show', ['id'=>7], ['tab'=>'profile'], 303);
	$routes->fallback(static fn()=>404);
	$t->throws(static fn()=>$routes->match('GET', '/null', null), RuntimeException::class);

	$routes->group(['prefix'=>'api', 'as'=>'api.', 'middleware'=>['auth'], 'without_middleware'=>['csrf']], static function(RouteCollection $group): void {
		$group->domain('api.example.test', static function(RouteCollection $domain): void {
			$domain->controller('UserController', static function(RouteCollection $controller): void {
				$controller->defaults(['page'=>1], static function(RouteCollection $defaults): void {
					$defaults->where('id', '[1-9][0-9]*', static fn(RouteCollection $where)=>$where->get('/users/{id}', 'show', ['name'=>'user.show']));
				});
			});
		});
	});
	$routes->prefix('constraints', static function(RouteCollection $group): void {
		$group->whereNumber(['number'], static fn(RouteCollection $r)=>$r->get('/{number}', static fn()=>1));
		$group->whereAlpha('alpha', static fn(RouteCollection $r)=>$r->get('/{alpha}', static fn()=>1));
		$group->whereAlphaNumeric('alpha_num', static fn(RouteCollection $r)=>$r->get('/{alpha_num}', static fn()=>1));
		$group->whereUuid('uuid', static fn(RouteCollection $r)=>$r->get('/{uuid}', static fn()=>1));
		$group->whereUlid('ulid', static fn(RouteCollection $r)=>$r->get('/{ulid}', static fn()=>1));
		$group->whereIn('state', ['open', 'closed'], static fn(RouteCollection $r)=>$r->get('/{state}', static fn()=>1));
	});
	$t->throws(static fn()=>$routes->defaults([], null), RuntimeException::class);
	$t->throws(static fn()=>$routes->where([], null), RuntimeException::class);
	$t->throws(static fn()=>$routes->whereNumber([], static fn()=>null), RuntimeException::class);
	$t->throws(static fn()=>$routes->whereIn('state', [], static fn()=>null), RuntimeException::class);

	$user=$routes->named('api.user.show');
	$t->isTrue($user instanceof RouteDefinition);
	$t->same('/api/users/{id}', $user->path());
	$t->same('UserController@show', $user->handler());
	$t->same(['auth'], $user->middlewareDefinitions());
	$t->same(['csrf'], $user->excludedMiddlewareDefinitions());
	$t->same(['locale'=>'en', 'page'=>1], $user->defaultsValues());
	$t->same('//api.example.test/api/users/7?locale=en&page=1&tab=profile', $routes->url('api.user.show', ['id'=>7], ['tab'=>'profile']));
	$t->same(null, $routes->named('missing'));
	$t->contains('api.user.show', array_column($routes->list(), 'name'));
	$manifest=$routes->compile(['suite'=>'coverage']);
	$t->same('coverage', $manifest['metadata']['app']);
	$t->same('coverage', $manifest['metadata']['suite']);

	$signed=$routes->signedUrl('api.user.show', ['id'=>9], ['tab'=>'a'], time()+60);
	$parts=parse_url($signed);
	parse_str((string)($parts['query'] ?? ''), $query);
	$t->isTrue(SignedUrl::validUrl((string)($parts['path'] ?? ''), $query, 'route-secret'));
	$temporary=$routes->temporarySignedUrl('api.user.show', time()+60, ['id'=>10]);
	$t->contains('signature=', $temporary);
	$t->isTrue($routes->revision()>0);
	RouteCollection::flushMacros();
})->tag('mvc', 'routes', 'coverage')->group('framework-coverage');

test('mvc resource expansion covers conventional singleton shallow batch and option variants', static function(Context $t): void {
	$app=new MvcApplication('resources', [
		'resource_verbs'=>['create'=>'new', 'edit'=>'change'],
	]);
	$routes=$app->routes();
	$resource=$routes->resource('admin/categories', 'CategoryController', [
		'shallow'=>true,
		'parameters'=>['categories'=>'category_id'],
		'names'=>['index'=>'category.list'],
		'actions'=>['show'=>'display'],
		'verbs'=>['edit'=>'revise'],
		'middleware'=>['web'],
		'middleware_for'=>['show'=>['audit']],
		'without_middleware_for'=>['destroy'=>['csrf']],
		'action_options'=>['update'=>['defaults'=>['mode'=>'full']]],
		'options_for'=>['store'=>['where'=>['tenant'=>'[0-9]+']]],
	]);
	$t->same(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'], array_keys($resource));
	$t->same('/categories/{category_id}', $resource['show']->path());
	$t->same('CategoryController@display', $resource['show']->handler());
	$t->same('category.list', $resource['index']->nameValue());
	$t->same('categories.show', $resource['show']->nameValue());
	$t->same(['web', 'audit'], $resource['show']->middlewareDefinitions());
	$t->same(['csrf'], $resource['destroy']->excludedMiddlewareDefinitions());
	$t->same(['mode'=>'full'], $resource['update']->defaultsValues());
	$t->same('/admin/categories/new', $resource['create']->path());
	$t->same('/categories/{category_id}/revise', $resource['edit']->path());

	$t->same(['index', 'store'], array_keys($routes->resource('posts', 'PostController', ['only'=>['index', 'store']])));
	$t->same(['index', 'store', 'show', 'update', 'destroy'], array_keys($routes->apiResource('photos', 'PhotoController')));
	$t->same(['create', 'store', 'show', 'edit', 'update', 'destroy'], array_keys($routes->singletonResource('profile', 'ProfileController')));
	$t->same(['store', 'show', 'update', 'destroy'], array_keys($routes->apiSingletonResource('settings', 'SettingsController')));
	$t->throws(static fn()=>$routes->resource('', 'Controller'), RuntimeException::class);
	$t->throws(static fn()=>$routes->singletonResource('/', 'Controller'), RuntimeException::class);

	$batch=$routes->resources([
		'users'=>'UserController',
		'comments'=>['CommentController', ['only'=>['index']]],
		'tags'=>['controller'=>'TagController', 'options'=>['only'=>['show']], 'param'=>'tag_key'],
		123=>'SkippedController',
		''=>'SkippedController',
		'bad'=>42,
	], ['middleware'=>['api']]);
	$t->same(['users', 'comments', 'tags'], array_keys($batch));
	$t->same(['index'], array_keys($batch['comments']));
	$t->same('/tags/{tag_key}', $batch['tags']['show']->path());
	$t->same(['photos'], array_keys($routes->apiResources(['photos'=>'PhotoController'], ['only'=>['index']])));
	$t->same(['account'], array_keys($routes->singletonResources(['account'=>'AccountController'], ['only'=>['show']])));
	$t->same(['preferences'], array_keys($routes->apiSingletonResources(['preferences'=>'PreferenceController'], ['only'=>['show']])));
})->tag('mvc', 'routes', 'coverage')->group('framework-coverage');

test('mvc route definitions cover mutations matching macros and introspection', static function(Context $t): void {
	RouteDefinition::flushMacros();
	$changes=0;
	$route=RouteDefinition::make(['GET', 'POST'], '/items/{id}', static fn()=>null, [
		'name_prefix'=>'api.', 'middleware'=>['web'], 'without_middleware'=>['csrf'],
		'bindings'=>['id'=>'Item'], 'defaults'=>['id'=>1], 'where'=>['id'=>'^[0-9]+$'],
		'domain'=>'{tenant}.example.test',
	]);
	$route->onChange(static function() use (&$changes): void { $changes++; })
		->name('items.show')->middleware('auth', ['audit'])->withoutMiddleware('guest')
		->whereNumber('id')->whereAlpha('alpha')->whereAlphaNumeric('code')->whereUuid('uuid')->whereUlid('ulid')
		->whereIn('state', ['open', 'closed'])->defaults('page', 1);
	$t->same(['GET', 'POST'], $route->methods());
	$t->same('/items/{id}', $route->path());
	$t->same('api.items.show', $route->nameValue());
	$t->same('{tenant}.example.test', $route->domainValue());
	$t->same(['id'=>'Item'], $route->modelBindings());
	$t->same(['id'=>1, 'page'=>1], $route->defaultsValues());
	$t->same(['web', 'auth', 'audit'], $route->middlewareDefinitions());
	$t->same(['csrf', 'guest'], $route->excludedMiddlewareDefinitions());
	$t->isTrue($changes>5);
	$params=[];
	$t->isTrue($route->matches('GET', '/items/42', $params, 'shop.example.test'));
	$t->same('42', $params['id']);
	$t->isFalse($route->matches('DELETE', '/items/42', $params, 'shop.example.test'));
	$t->isFalse($route->matches('GET', '/items/nope', $params, 'shop.example.test'));
	$t->same('//shop.example.test/items/8?page=1&tab=details', $route->url(['id'=>8, 'tenant'=>'shop'], ['tab'=>'details']));
	$t->same('/items/{id}', $route->compile()['path']);

	RouteDefinition::macro('label', function(): ?string { return $this->nameValue(); });
	$t->isTrue(RouteDefinition::hasMacro('label'));
	$t->same('api.items.show', $route->label());
	$t->throws(static fn()=>$route->absentMacro(), BadMethodCallException::class);
	RouteDefinition::flushMacros();
})->tag('mvc', 'routes', 'coverage')->group('framework-coverage');

test('mvc applications load route configuration shapes files and manifest settings', static function(Context $t): void {
	$workspace=$t->workspace('mvc-routes');
	$file=$workspace->file('routes.php', "<?php\nreturn static function(\\Dataphyre\\Mvc\\RouteCollection \$routes): void { \$routes->get('/from-file', static fn()=>1, ['name'=>'from.file']); };\n");
	$manifest=$workspace->path('manifest.php');
	$app=new MvcApplication('configured', [
		'manifest_cache'=>['file'=>$manifest],
		'routes'=>[
			static fn(RouteCollection $routes)=>$routes->get('/closure', static fn()=>1, ['name'=>'closure']),
			$file,
			['path'=>'/array', 'method'=>'POST', 'handler'=>static fn()=>2, 'name'=>'array'],
			['path'=>'/view', 'template'=>'page', 'data'=>['a'=>1]],
			['path'=>'/redirect', 'location'=>'/array', 'status'=>301],
			['path'=>'/to-route', 'to_route'=>'array', 'parameters'=>[], 'query'=>['a'=>1]],
			RouteDefinition::make('GET', '/definition', static fn()=>3, ['name'=>'definition']),
		],
	]);
	$t->same($manifest, $app->manifestCacheFile());
	$t->isTrue($app->manifestCacheEnabled());
	$t->same(7, count($app->routes()->all()));
	$t->same(1, count($app->routeSources()));
	$t->isTrue($app->dispatcher()===$app->dispatcher());
	$app->bootProviders();

	$single=new MvcApplication('single', ['routes'=>['path'=>'/single', 'handler'=>static fn()=>1]]);
	$t->same(1, count($single->routes()->all()));
	$callable=new MvcApplication('callable', ['routes'=>static fn(RouteCollection $routes)=>$routes->get('/one', static fn()=>1)]);
	$t->same(1, count($callable->routes()->all()));
	$t->same(null, (new MvcApplication('invalid', ['routes'=>new stdClass(), 'controllers'=>[], 'views'=>[]]))->controllerNamespace());
})->tag('mvc', 'routes', 'coverage')->group('framework-coverage');
