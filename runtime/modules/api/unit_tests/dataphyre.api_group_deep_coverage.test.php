<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Api\Api;
use Dataphyre\Api\ApiCallableBinding;
use Dataphyre\Api\ApiContext;
use Dataphyre\Api\ApiGroup;
use Dataphyre\Api\Endpoint;
use Dataphyre\Api\SecurityScheme;
use Dataphyre\Http\Request;
use Dataphyre\Templating\BindingContext;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(...$arguments): void {}
}
if(!class_exists('dataphyre\\core', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; class core { public static function dialback(...$arguments): mixed { return null; } public static function load_framework_module(string $module): void {} public static function load_framework_modules(array $modules): void {} }');
}

framework(['api', 'http', 'routing', 'templating']);

final class DpApiCallableCoverageTarget {
	public static function two(mixed $apiContext, mixed $request): array {
		return ['api'=>$apiContext instanceof ApiContext, 'request'=>$request instanceof Request];
	}

	public static function three(mixed $apiContext, mixed $request, array $route): string {
		return (string)($route['path_template'] ?? 'missing');
	}

	public static function identity(): string {
		return 'static-identity';
	}
}

final class DpApiInvokableCoverageTarget {
	public function __invoke(mixed $apiContext): string {
		return $apiContext instanceof ApiContext ? 'invoked' : 'missing';
	}
}

test('api group applies every shared endpoint concern and normalizes nested paths', static function(Context $t): void {
	$any=SecurityScheme::apiKey('groupKey', 'X-Group-Key', 'header');
	$all=SecurityScheme::bearer('groupBearer');
	$handler=static fn(): array=>['ok'=>true];
	$group=ApiGroup::make(' partner ')
		->prefix('/v2/shops/')
		->middleware('auth', ['tenant'])
		->tag('shops', ['partners', ['shops', '', 'nested']])
		->auth($any)
		->authAll($all)
		->server('https://api.example.test', 'Primary')
		->server('https://backup.example.test')
		->withTrace(false, ['sample'=>'all'])
		->beforeExecute('Hooks::before', ['priority'=>1])
		->afterExecute('Hooks::after')
		->onError('Hooks::error', ['report'=>true])
		->dispatchDefaults(['source'=>'group'])
		->dispatchDefaults(['trust_auth'=>true]);

	$endpoint=$group->methods(['GET', 'HEAD'], '/{shopId}/', $handler);
	$t->instanceOf(Endpoint::class, $endpoint);
	$compiled=$endpoint->compile()['api'];
	$t->same('/v2/shops/{shopId}', $compiled['path']);
	$t->same(['GET', 'HEAD'], $compiled['methods']);
	$t->contains('shops', $compiled['tags']);
	$t->contains('partners', $compiled['tags']);
	$t->contains('nested', $compiled['tags']);
	$t->same('partner', $compiled['profile']['name']);
	$t->same('/v2/shops', $compiled['profile']['prefix']);
	$t->same('group', $compiled['dispatch']['source']);
	$t->isTrue($compiled['dispatch']['trust_auth']);
	$t->isFalse($compiled['trace']['enabled']);
	$t->same(2, count($compiled['servers']));
	$t->same(1, count($compiled['lifecycle']['before']));
	$t->same(1, count($compiled['lifecycle']['after']));
	$t->same(1, count($compiled['lifecycle']['error']));

	$root=$group->get('/', $handler)->compile()['api'];
	$t->same('/v2/shops', $root['path']);
})->tag('api', 'coverage')->group('framework-coverage');

test('api group verb helpers cover empty profile prefix and root normalization branches', static function(Context $t): void {
	$handler=static fn(): null=>null;
	$group=ApiGroup::make('   ')->prefix('');
	$cases=[
		$group->get('get/', $handler),
		$group->post('/post', $handler),
		$group->put('put', $handler),
		$group->patch('/patch/', $handler),
		$group->delete('delete', $handler),
		$group->any('/any', $handler),
	];
	$expected=[
		['GET', '/get'],
		['POST', '/post'],
		['PUT', '/put'],
		['PATCH', '/patch'],
		['DELETE', '/delete'],
		['ANY', '/any'],
	];
	foreach($cases as $index=>$endpoint){
		$compiled=$endpoint->compile()['api'];
		$t->same([$expected[$index][0]], $compiled['methods']);
		$t->same($expected[$index][1], $compiled['path']);
		$t->isFalse(isset($compiled['profile']));
	}
	$applied=$group->apply(Endpoint::get('/applied', $handler))->compile()['api'];
	$t->same('/applied', $applied['path']);
})->tag('api', 'coverage')->group('framework-coverage');

test('api static facade covers endpoint groups discovery dispatch cache auth and execution bridges', static function(Context $t): void {
	$handler=static fn(): array=>['ok'=>true];
	$group=Api::group([
		'prefix'=>'/facade',
		'middleware'=>['auth', ['tenant']],
		'tags'=>['facade', 'group'],
		'trace'=>['enabled'=>false, 'sample'=>'all'],
		'dispatch'=>['source'=>'facade'],
	]);
	$t->same('/facade/items', $group->get('/items', $handler)->compile()['api']['path']);
	$profile=Api::profile('mobile', [
		'prefix'=>'/mobile',
		'middleware'=>'auth',
		'tags'=>'mobile',
	]);
	$t->same('mobile', $profile->get('/items', $handler)->compile()['api']['profile']['name']);

	$builders=[
		Api::methods(['GET', 'HEAD'], '/methods', $handler),
		Api::get('/get', $handler),
		Api::post('/post', $handler),
		Api::put('/put', $handler),
		Api::patch('/patch', $handler),
		Api::delete('/delete', $handler),
		Api::any('/any', $handler),
	];
	foreach($builders as $builder){
		$t->instanceOf(Endpoint::class, $builder);
	}
	$t->producesStableResult(static fn()=>Api::manager());
	$t->same(3, count(Api::documentationRoutes()));
	$t->same([], Api::discoverManifest(['routes'=>[]]));
	$t->isFalse(Api::dispatch(['path'=>'/missing'])['ok']);
	$t->isFalse(Api::dispatchBatch([['path'=>'/missing']])['ok']);
	$t->isFalse(Api::dispatchChain([['path'=>'/missing']])['ok']);
	$t->same(0, Api::clearEndpointCache('api-group-missing'));
	$t->same(null, Api::authorizeCompiledRoute([], Request::create('GET', '/')));
	$t->same(null, Api::executeCompiledRoute([], Request::create('GET', '/')));

	try{
		$t->isTrue(is_array(Api::discoverApplication(null)));
	}
	catch(Throwable $exception){
		$t->notEmpty($exception->getMessage());
	}
	try{
		$t->isTrue(is_array(Api::openApiDocument(null, ['title'=>'Coverage'])));
	}
	catch(Throwable $exception){
		$t->notEmpty($exception->getMessage());
	}
})->tag('api', 'coverage')->group('framework-coverage');

test('api callable binding covers resolver arities callable shapes and identity normalization', static function(Context $t): void {
	$request=Request::create('GET', '/binding', [], [], [], [], [], ['id'=>'7']);
	$apiContext=new ApiContext($request, ['path_template'=>'/binding/{id}']);
	$context=new BindingContext('api-binding', true, [], [], [], ['api_context'=>$apiContext]);
	$withoutApi=new BindingContext('api-binding-empty', true);

	$zero=new ApiCallableBinding(static fn(): string=>'zero', 'zero');
	$t->same('zero', $zero->name());
	$t->same('zero', $zero->resolve($context));
	$t->same(null, $zero->cacheIdentity($context));

	$one=new ApiCallableBinding(static fn(mixed $api): bool=>$api instanceof ApiContext, 'one', null, ' key ');
	$t->isTrue($one->resolve($context));
	$t->same(['key'=>'key'], $one->cacheIdentity($context));
	$t->same(null, (new ApiCallableBinding(static fn()=>null, identity:'  '))->cacheIdentity($context));

	$two=new ApiCallableBinding([DpApiCallableCoverageTarget::class, 'two'], 'two', 'target', ['tenant'=>42]);
	$t->same(['api'=>true, 'request'=>true], $two->resolve($context));
	$t->same(['tenant'=>42], $two->cacheIdentity($context));
	$t->same('array', $two->metadata()['cache_identity_mode']);

	$three=new ApiCallableBinding(DpApiCallableCoverageTarget::class.'::three', 'three', identity:42);
	$t->same('/binding/{id}', $three->resolve($context));
	$t->same(['value'=>42], $three->cacheIdentity($context));
	$t->same('int', $three->metadata()['cache_identity_mode']);

	$four=new ApiCallableBinding(
		static fn(mixed $api, mixed $request, array $route, BindingContext $binding): array=>[
			$api instanceof ApiContext, $request instanceof Request, $route['path_template'] ?? null, $binding->templateName(),
		],
		'four',
		identity:static fn(BindingContext $binding): array=>['binding'=>$binding->templateName()]
	);
	$t->same([true, true, '/binding/{id}', 'api-binding'], $four->resolve($context));
	$t->same(['binding'=>'api-binding'], $four->cacheIdentity($context));
	$t->same('callable', $four->metadata()['cache_identity_mode']);

	$variadic=new ApiCallableBinding(static fn(mixed ...$arguments): int=>count($arguments));
	$t->same(4, $variadic->resolve($context));
	$invokable=new ApiCallableBinding(new DpApiInvokableCoverageTarget());
	$t->same('invoked', $invokable->resolve($context));
	$t->same('missing', $invokable->resolve($withoutApi));

	$staticIdentity=new ApiCallableBinding(static fn()=>null, identity:[DpApiCallableCoverageTarget::class, 'identity']);
	$t->same(['key'=>'static-identity'], $staticIdentity->cacheIdentity($context));
	$t->same(null, (new ApiCallableBinding(static fn()=>null, identity:new stdClass()))->cacheIdentity($context));
})->tag('api', 'coverage')->group('framework-coverage');
