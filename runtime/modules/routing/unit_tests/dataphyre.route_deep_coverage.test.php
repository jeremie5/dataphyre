<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Routing\Route;
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

test('route factories and normalizers cover every HTTP verb and canonical fallback', static function(Context $t): void {
	$handler=static fn(): null=>null;
	$routes=[
		Route::head('/head', $handler),
		Route::put('/put', $handler),
		Route::patch('/patch', $handler),
		Route::delete('/delete', $handler),
		Route::options('/options', $handler),
		Route::any('/any', $handler),
		Route::post('/post', $handler),
	];
	$methods=['HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'ANY', 'POST'];
	foreach($routes as $index=>$route){
		$t->same([$methods[$index]], $route->compile()['methods']);
	}
	$t->same(['GET', 'POST'], Route::normalizeMethods([' get ', '', 'POST', 'GET']));
	$t->same(['FALLBACK'], Route::normalizeMethods(['', '  '], ['FALLBACK']));
	$t->same('/nested/path', Route::normalizePath('nested/path/'));
	$t->same('/', Route::normalizePath(''));
	$t->same('shop.example.test', Route::normalizeDomain(' HTTPS://.Shop.Example.Test./ignored '));
})->tag('routing', 'coverage')->group('framework-coverage');

test('route URL generation covers splats optionals domains spillover queries and missing parameters', static function(Context $t): void {
	$t->same(
		'/files/reports/2026/Ada%20Lovelace/summary?page=2&download=1',
		Route::url('/files/{...path}/{name}/{section?}', [
			'path'=>['reports', '2026'], 'name'=>'Ada Lovelace', 'section'=>'summary', 'page'=>1,
		], ['download'=>1, 'page'=>2])
	);
	$t->same('/files/a/b', Route::url('/files/{...path}/{optional?}', ['path'=>'a/b', 'optional'=>null]));
	$t->same('/files/a', Route::url('/files/{...path}/{optional?}', ['path'=>['a'], 'optional'=>'']));
	$t->same('//north.example.test/items/7', Route::url('/items/{id}', ['tenant'=>'north', 'id'=>7], [], '{tenant}.example.test'));
	$t->same('//.example.test/items', Route::url('/items', [], [], '{tenant?}.example.test'));
	$t->same('//static.example.test/items', Route::url('/items', [], [], 'static.example.test'));
	$t->throws(static fn()=>Route::url('/files/{...path}', []), RuntimeException::class);
	$t->throws(static fn()=>Route::url('/items/{id}', []), RuntimeException::class);

	$parameterized=$t->nonPublic(Route::class)->capture('parameterizePattern', pattern: '/{...rest}', parameters: [], splat: false);
	$t->same('/{...rest}', $parameterized->result());
})->tag('routing', 'coverage')->group('framework-coverage');

test('route middleware normalization covers aliases classes descriptors modules and invalid declarations', static function(Context $t): void {
	$t->same(['alias'=>'auth'], Route::normalizeMiddleware(' auth '));
	$t->same(['alias'=>'role', 'parameters'=>['admin', 'editor']], Route::normalizeMiddleware('role:admin,editor'));
	$t->same(['class'=>'App\\Middleware\\Auth'], Route::normalizeMiddleware('\\App\\Middleware\\Auth\\'));
	$classDescriptor=Route::normalizeMiddleware([
		'class'=>'\\App\\Middleware\\Tenant\\',
		'parameters'=>'strict',
		'module'=>' API ',
		'bootstrap'=>' /app/bootstrap.php ',
	]);
	$t->same('App\\Middleware\\Tenant', $classDescriptor['class']);
	$t->same(['strict'], $classDescriptor['parameters']);
	$t->same(['api'], $classDescriptor['modules']);
	$t->same('/app/bootstrap.php', $classDescriptor['bootstrap']);
	$aliasDescriptor=Route::normalizeMiddleware([
		'name'=>'tenant',
		'parameters'=>['one', 2],
		'modules'=>[' Cache ', '', 'CACHE'],
	]);
	$t->same('tenant', $aliasDescriptor['alias']);
	$t->same(['one', 2], $aliasDescriptor['parameters']);
	$t->same(['cache'], $aliasDescriptor['modules']);
	$t->throws(static fn()=>Route::normalizeMiddleware('  '), RuntimeException::class);
	$t->throws(static fn()=>Route::normalizeMiddleware([]), RuntimeException::class);
	$t->throws(static fn()=>Route::normalizeMiddleware(['unknown'=>'value']), RuntimeException::class);

	$compiled=Route::get('/middleware', static fn()=>null)
		->middleware(['auth', 'role:admin'], ['alias'=>'tenant', 'modules'=>['api']])
		->compile();
	$t->same(3, count($compiled['middleware']));
})->tag('routing', 'coverage')->group('framework-coverage');

test('route fluent constraints metadata domains and regex compilation cover every builder path', static function(Context $t): void {
	$route=Route::methods([' get ', 'GET', '', 'post'], '/api/{id}/{slug?}/{kind}/{code}/{uuid}/{...tail}', ['ArrayHandler', 'run'])
		->name('api.show')
		->domain('{tenant}.Example.Test')
		->middleware('auth')
		->whereNumber(['id', 'tenant'])
		->whereAlpha('slug')
		->whereAlphaNumeric('code')
		->whereUuid('uuid')
		->whereIn('kind', ['draft', '', 'a.b'])
		->where(['tail'=>'^.+$', ''=>'ignored', 'empty'=>''])
		->defaults(['slug'=>'current', ''=>'ignored'])
		->metadata(['docs'=>['summary'=>'Original'], 'one'=>1])
		->metadata(['docs'=>['summary'=>'Updated', 'tag'=>'api']]);
	$compiled=$route->compile();
	$t->same(['GET', 'POST'], $compiled['methods']);
	$t->same('api.show', $compiled['name']);
	$t->contains('tenant', $compiled['domain_regex']);
	$t->contains('slug', $compiled['path_regex']);
	$t->contains('tail', $compiled['splat_parameters']);
	$t->same('[0-9]+', $compiled['constraints']['id']);
	$t->same('[0-9]+', $compiled['constraints']['tenant']);
	$t->same('draft|a\\.b', $compiled['constraints']['kind']);
	$t->same('Updated', $compiled['metadata']['docs']['summary']);
	$t->same('api', $compiled['metadata']['docs']['tag']);
	$t->same(['ArrayHandler', 'run'], $compiled['handler']);

	$exactDomain=Route::get('/health', 'Health::show')
		->domain('HTTPS://Health.Example.Test/path')
		->compile();
	$t->same('health.example.test', $exactDomain['exact_domain']);
	$domainConstraint=Route::get('/', 'Root::show')
		->domain('{tenant}.example.test')
		->where('tenant', '^shop[0-9]+$')
		->compile();
	$t->same('shop[0-9]+', $domainConstraint['constraints']['tenant']);
	Route::get('/blank-domain', 'Handler')->domain('   ')->compile();
	Route::get('/empty-in', 'Handler')->whereIn('state', ['', ''])->compile();
	$t->throws(static fn()=>Route::get('/invalid', new stdClass())->compile(), RuntimeException::class);
})->tag('routing', 'coverage')->group('framework-coverage');

test('route private regex helpers cover root optional literals defaults and empty constraints', static function(Context $t): void {
	$route=Route::get('/placeholder', static fn()=>null);
	$routeInternals=$t->nonPublic($route);
	$rootRegex=$routeInternals->capture('compilePathRegex', path: '/', splatParameters: [], usedConstraints: []);
	$t->same('#^/$#', $rootRegex->result());
	$compiled=$routeInternals->capture('compilePathRegex', path: '/literal/{id}/{optional?}/{...rest}', splatParameters: [], usedConstraints: []);
	$regex=$compiled->result();
	$t->contains('optional', $regex);
	$t->same(['rest'], $compiled->argument('splatParameters'));
	$t->same([], $compiled->argument('usedConstraints'));

	$domain=$routeInternals->capture('compileDomainRegex', domain: '{tenant}.Example.Test', usedConstraints: []);
	$t->contains('tenant', $domain->result());
	$t->same([], $domain->argument('usedConstraints'));
	$t->same('', $routeInternals->invoke('normalizeConstraint', '  '));
	$t->same('digits', $routeInternals->invoke('normalizeConstraint', '^digits$'));
})->tag('routing', 'coverage')->group('framework-coverage');
