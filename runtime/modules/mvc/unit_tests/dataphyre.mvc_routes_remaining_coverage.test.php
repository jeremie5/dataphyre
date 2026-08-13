<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\ProviderRegistry;
use Dataphyre\Mvc\RouteCollection;
use Dataphyre\Mvc\RouteDefinition;
use Dataphyre\Mvc\RouteList;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'routing', 'mvc']);

test('mvc application edge configuration loads traversable providers and returned route-file shapes', static function(Context $t): void {
	$definitionFile=__DIR__.'/fixtures/mvc_route_definition_fixture.php';
	$arrayFile=__DIR__.'/fixtures/mvc_route_array_fixture.php';
	$app=new MvcApplication('application-remaining', [
		'providers'=>new ArrayIterator([]),
		'manifest_cache'=>true,
		'views'=>[],
		'routes'=>$definitionFile,
	]);

	$t->same('application-remaining', $app->name());
	$t->isTrue(is_array($app->config()));
	$t->producesStableResult(static fn()=>$app->container());
	$t->instanceOf(ProviderRegistry::class, $app->providers());
	$t->same(null, $app->viewPath());
	$t->contains('/cache/mvc/application-remaining.routes.php', str_replace('\\', '/', (string)$app->manifestCacheFile()));
	$t->isTrue($app->manifestCacheEnabled());
	$t->instanceOf(RouteDefinition::class, $app->routes()->named('fixture.definition'));
	$t->contains(str_replace('\\', '/', realpath($definitionFile) ?: $definitionFile), array_map(
		static fn(string $path): string=>str_replace('\\', '/', $path),
		array_keys($app->routeSources())
	));

	$arrayApp=new MvcApplication('array-file-remaining', [
		'providers'=>'invalid',
		'routes'=>$arrayFile,
	]);
	$t->instanceOf(RouteDefinition::class, $arrayApp->routes()->named('fixture.array'));
	$t->contains(str_replace('\\', '/', realpath($arrayFile) ?: $arrayFile), array_map(
		static fn(string $path): string=>str_replace('\\', '/', $path),
		array_keys($arrayApp->routeSources())
	));

	$fromConfig=MvcApplication::fromConfig('from-config-remaining', [
		'manifest_cache'=>'/tmp/from-config-routes.php',
	]);
	$t->same('/tmp/from-config-routes.php', $fromConfig->manifestCacheFile());
})->tag('mvc', 'routes', 'coverage')->group('framework-coverage');

test('mvc route collection remaining group and resource helper signatures stay deterministic', static function(Context $t): void {
	$app=new MvcApplication('route-collection-remaining');
	$routes=$app->routes();
	$routes->name('named.', static fn(RouteCollection $group)=>$group->get('/named', static fn()=>1, ['name'=>'route']));
	$routes->middleware(['web'], static fn(RouteCollection $group)=>$group->get('/middleware', static fn()=>1));
	$routes->defaults('locale', 'en', static fn(RouteCollection $group)=>$group->get('/defaults', static fn()=>1));
	$routes->where(['id'=>'[0-9]+'], static fn(RouteCollection $group)=>$group->get('/where/{id}', static fn()=>1));
	$t->instanceOf(RouteDefinition::class, $routes->named('named.route'));
	$t->same(['web'], $routes->all()[1]->middlewareDefinitions());
	$t->same(['locale'=>'en'], $routes->all()[2]->defaultsValues());
	$t->same(['id'=>'[0-9]+'], $routes->all()[3]->constraints());

	$internals=$t->nonPublic($routes);
	$t->same('PlainController', $internals->invoke('groupControllerHandler', 'PlainController', []));
	$t->same('Namespaced\\Handler', $internals->invoke('groupControllerHandler', 'Namespaced\\Handler', ['controller'=>'GroupController']));
	$t->same('category_key', $internals->invoke('resourceParameter', 'admin/categories', ['parameters'=>['admin/categories'=>'category_key']]));
	$t->same('category', $internals->invoke('resourceParameter', 'categories', []));
	$t->same('person', $internals->invoke('resourceParameter', 'person', []));
	$t->same('create', $internals->invoke('resourceUriVerb', 'create', []));
	$t->same('inventory', $internals->invoke('resourceBaseRouteName', 'people', ['as'=>' inventory. ']));
	$t->same('teams.members', $internals->invoke('resourceRouteName', 'teams/{team}/members'));
	$t->same(['', []], $internals->invoke('resourceBatchDefinition', ['controller'=>42], []));
	$t->same(['Controller', []], $internals->invoke('resourceBatchDefinition', ['Controller', 'invalid-options'], []));

	$t->setEnvironmentForTest(['DATAPHYRE_MVC_SIGNING_KEY'=>'environment-secret']);
	$t->same('environment-secret', $internals->invoke('signedUrlSecret'));
})->tag('mvc', 'routes', 'coverage')->group('framework-coverage');

test('mvc route list labels every supported handler and middleware descriptor shape', static function(Context $t): void {
	$target=new class {
		public function handle(): void {}
	};
	$internals=$t->nonPublic(RouteList::class);
	$t->same('Controller@show', $internals->invoke('handlerLabel', 'Controller@show'));
	$t->same('include:/tmp/route.php', $internals->invoke('handlerLabel', ['type'=>'include', 'target'=>'/tmp/route.php']));
	$t->same('callable', $internals->invoke('handlerLabel', ['type'=>'callable']));
	$t->same($target::class.'@handle', $internals->invoke('handlerLabel', [$target, 'handle']));
	$t->same('array', $internals->invoke('handlerLabel', ['unknown'=>true]));
	$t->same(stdClass::class, $internals->invoke('handlerLabel', new stdClass()));
	$t->same('int', $internals->invoke('handlerLabel', 42));

	$labels=$internals->invoke('middlewareLabels', [
		['alias'=>'throttle', 'parameters'=>['60', '1']],
		['class'=>'\\App\\Middleware\\Audit'],
		['target'=>static fn()=>null],
		['unknown'=>true],
		42,
	]);
	$t->same(['throttle:60,1', 'App\\Middleware\\Audit', 'callable', 'array', 'int'], $labels);

	$route=RouteDefinition::make('GET', '/empty-constraint/{id}', static fn()=>1)->where('id', '');
	$t->same([], $route->constraints());
})->tag('mvc', 'routes', 'coverage')->group('framework-coverage');
