<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Routing\ControllerAction;
use Dataphyre\Routing\Route;
use Dataphyre\Routing\RouteManifest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(...$arguments): void {}
}
if(!class_exists('dataphyre\\core', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; class core { public static function dialback(...$arguments): mixed { return null; } public static function load_framework_module(string $module): void {} public static function load_framework_modules(array $modules): void {} }');
}

framework(['routing']);

test('controller action covers instance and namespaced string construction', static function(Context $t): void {
	$instance=ControllerAction::instance('\\App\\Controllers\\Users', ' show ', ['bootstrap'=>' /app/bootstrap.php '])->compile();
	$t->same('App\\Controllers\\Users', $instance['class']);
	$t->same('show', $instance['method']);
	$t->isFalse($instance['static']);
	$t->same('/app/bootstrap.php', $instance['bootstrap']);

	$namespaced=ControllerAction::fromString('Users@', '\\App\\Controllers\\', ['static'=>true]);
	$t->same('App\\Controllers\\Users', $namespaced->compile()['class']);
	$t->same('__invoke', $namespaced->compile()['method']);
	$t->isTrue($namespaced->compile()['static']);
	$compiledRoute=Route::get('/users', ControllerAction::instance('App\\Controllers\\Users', 'index'))->compile();
	$t->same('App\\Controllers\\Users', $compiledRoute['handler']['class']);
	$t->same('index', $compiledRoute['handler']['method']);
})->tag('routing', 'coverage')->group('framework-coverage');

test('route manifest compiles route objects arrays names metadata and invalid entries', static function(Context $t): void {
	$route=Route::get('/users/{id}', static fn()=>null)->name('users.show')->defaults(['id'=>7]);
	$manifest=RouteManifest::compile([
		$route,
		['name'=>'health', 'exact_path'=>'/health', 'metadata'=>['kind'=>'health']],
		['name'=>'health', 'exact_path'=>'/duplicate'],
	], ['application'=>'coverage']);
	$t->same(1, $manifest['version']);
	$t->same('coverage', $manifest['metadata']['application']);
	$t->same(3, count($manifest['routes']));
	$t->same(0, $manifest['named_routes']['users.show']);
	$t->same(1, $manifest['named_routes']['health']);
	$t->same(['version'=>1, 'metadata'=>[], 'routes'=>[]], RouteManifest::compile([]));
	$t->throws(static fn()=>RouteManifest::compile([new stdClass()]), RuntimeException::class);
})->tag('routing', 'coverage')->group('framework-coverage');

test('route manifest named URLs cover index fallback defaults domains exact paths and failures', static function(Context $t): void {
	$manifest=[
		'named_routes'=>[
			'fast'=>0,
			'fallback'=>'invalid-index',
			'wrong'=>2,
		],
		'routes'=>[
			['name'=>'fast', 'exact_path'=>'/fast'],
			['name'=>'fallback', 'path'=>'/orders/{id}', 'defaults'=>['id'=>9]],
			['name'=>'different', 'exact_path'=>'/different'],
			['name'=>'domain', 'path'=>'/shops/{shop}', 'domain'=>'{tenant}.example.test'],
			['name'=>'exact-parameter', 'exact_path'=>'/files/{name}'],
			['name'=>'broken'],
		],
	];
	$t->same('/fast', RouteManifest::namedUrl($manifest, ' fast '));
	$t->same('/orders/9?page=2', RouteManifest::namedUrl($manifest, 'fallback', [], ['page'=>2]));
	$t->same('//north.example.test/shops/catalog', RouteManifest::namedUrl($manifest, 'domain', ['tenant'=>'north', 'shop'=>'catalog']));
	$t->same('/files/report', RouteManifest::namedUrl($manifest, 'exact-parameter', ['name'=>'report']));
	$t->throws(static fn()=>RouteManifest::namedUrl($manifest, 'missing'), RuntimeException::class);
	$t->throws(static fn()=>RouteManifest::namedUrl($manifest, 'broken'), RuntimeException::class);
})->tag('routing', 'coverage')->group('framework-coverage');

test('route manifest metadata helpers cover complete malformed default and mutation shapes', static function(Context $t): void {
	$route=['metadata'=>['nullable'=>null, 'kind'=>'api']];
	$t->same($route['metadata'], RouteManifest::routeMetadata($route));
	$t->same('api', RouteManifest::routeMetadata($route, 'kind'));
	$t->same(null, RouteManifest::routeMetadata($route, 'nullable', 'fallback'));
	$t->same('fallback', RouteManifest::routeMetadata($route, 'missing', 'fallback'));
	$t->same([], RouteManifest::routeMetadata(['metadata'=>'invalid']));
	$t->same('fallback', RouteManifest::routeMetadata(['metadata'=>'invalid'], 'kind', 'fallback'));

	$t->same($route, RouteManifest::withRouteMetadata($route, '  ', 'ignored'));
	$created=RouteManifest::withRouteMetadata(['metadata'=>'invalid'], ' kind ', 'created');
	$t->same('created', $created['metadata']['kind']);
	$updated=RouteManifest::withRouteMetadata($route, 'kind', 'updated');
	$t->same('updated', $updated['metadata']['kind']);
})->tag('routing', 'coverage')->group('framework-coverage');
