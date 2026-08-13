<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Api\ApiManager;
use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['api','http','routing','sanitation','templating']);
suite('API manager deep coverage')->sandboxesRootpath('api_cache');
if(!function_exists('Dataphyre\Api\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace Dataphyre\Api; function tracelog(...$arguments): void {}');
}

final class DpApiManagerController {
	public static function staticHandle(Request $request,array $route): array {
		return ['kind'=>'static','path'=>$request->path(),'parameters'=>$route['parameters'] ?? []];
	}
	public function instanceHandle(Request $request,array $route): array {
		return ['kind'=>'instance','path'=>$request->path()];
	}
	public static function binding(\Dataphyre\Api\ApiContext $context): array {
		$calls=\Dataphyre\Test\TestState::channel('api.manager.deep')->increment('binding_calls');
		return ['call'=>$calls,'path'=>$context->path()];
	}
	public static function apiHandle(\Dataphyre\Api\ApiContext $context): array {
		return ['first'=>$context->binding('first'),'nested'=>$context->binding('nested.value')];
	}
}

final class DpApiManagerCacheTarget {
	public static function handle(\Dataphyre\Api\ApiContext $context): array {
		$calls=\Dataphyre\Test\TestState::channel('api.manager.deep')->increment('cache_calls');
		return ['ok'=>true,'calls'=>$calls,'page'=>$context->query('page')];
	}
	public static function error(\Dataphyre\Api\ApiContext $context): Response {
		$calls=\Dataphyre\Test\TestState::channel('api.manager.deep')->increment('cache_calls');
		return Response::json(['ok'=>false,'calls'=>$calls],500);
	}
}

test('api manager internal route normalization matches paths aliases and inherited requests',static function(Context $t): void {
	$private=$t->nonPublic(ApiManager::instance());
	$normalized=$private->invoke('normalizeInternalRequestDefinition',[
		'uri'=>'api/items/7','get'=>['page'=>2],'post'=>['name'=>'Ada'],
		'parameters'=>['id'=>7],'headers'=>['X-Test'=>'yes'],'cookies'=>['session'=>'one'],
		'server'=>['SERVER_NAME'=>'example.test'],'attributes'=>['custom'=>true],
	]);
	$t->same('POST',$normalized['method']);
	$t->same('/api/items/7',$normalized['path']);
	$t->same(7,$normalized['route_parameters']['id']);
	$t->throws(static fn()=>$private->invoke('normalizeInternalRequestDefinition',[]),RuntimeException::class);
	$aliasDefinition=$private->invoke('normalizeInternalRequestDefinition',[
		'endpoint'=>'items.show','profile'=>' Mobile V1 ','route'=>['id'=>7],
	]);
	$t->same('items.show',$aliasDefinition['alias']);
	$t->same('Mobile V1',$aliasDefinition['profile']);

	$routes=[
		null,
		['methods'=>['POST'],'exact_path'=>'/other','api'=>[]],
		['methods'=>['GET'],'exact_path'=>'/api/exact','api'=>['aliases'=>['exact.show']]],
		[
			'methods'=>['GET','HEAD'],'path_template'=>'/api/items/{id}/{rest}',
			'path_regex'=>'#^/api/items/(?P<id>[^/]+)/(?P<rest>.*)$#',
			'splat_parameters'=>['rest'],
			'api'=>['path'=>'/api/items/{id}/{rest}','aliases'=>['items.show','','/items/show/'],'profile'=>['name'=>'mobile_v1']],
		],
	];
	$t->same('/api/exact',$private->invoke('matchManifestRoute',$routes,'GET','api/exact')['exact_path']);
	$regex=$private->invoke('matchManifestRoute',$routes,'HEAD','/api/items/7/a/b');
	$t->same('7',$regex['parameters']['id']);
	$t->same(['a','b'],$regex['parameters']['rest']);
	$t->same(null,$private->invoke('matchManifestRoute',$routes,'DELETE','/api/exact'));
	$t->same(1,count($private->invoke('matchManifestRoutesByAlias',$routes,'GET','items.show','mobile_v1')));
	$t->same([],$private->invoke('matchManifestRoutesByAlias',$routes,'POST','items.show',null));
	$t->same([],$private->invoke('explodeSplatParameter','/'));
	$t->same(['a','b'],$private->invoke('explodeSplatParameter','/a//b/'));
	$t->isTrue($private->invoke('routeHasApiMetadata',$routes[2]));
	$t->isTrue($private->invoke('routeMatchesMethod',['methods'=>['ANY']],'PATCH'));
	$t->same(['items.show','items/show'],$private->invoke('routeAliases',$routes[3]));
	$t->same('mobile_v1',$private->invoke('routeProfileName',$routes[3]));

	$definition=['route_parameters'=>[],'query'=>['id'=>8],'body'=>['rest'=>['c','d']]];
	$t->same(['id'=>8,'rest'=>['c','d']],$private->invoke('resolveAliasRouteParameters',$routes[3],$definition));
	$t->same(null,$private->invoke('resolveAliasRouteParameters',$routes[3],['route_parameters'=>[],'query'=>[],'body'=>[]]));
	$t->same('/api/items/8/c/d',$private->invoke('interpolateRoutePathTemplate','/api/items/{id}/{rest}',['id'=>8,'rest'=>['c','d']],));
	$t->same(null,$private->invoke('interpolateRoutePathTemplate','/api/{id}',[]));
	$t->same('a/b',$private->invoke('stringifyRouteParameterValue',['a','b']));
	$t->same('a%20b',$private->invoke('stringifyRouteParameterValue','a b'));

	$base=Request::create('GET','/base',[],[],['base'=>'cookie'],['BASE'=>'server'],['X-Base'=>'yes']);
	$request=$private->invoke('internalRequestForRoute',['parameters'=>['id'=>7]],
		$normalized,
		['base_request'=>$base,'trust_auth'=>true,'auth'=>['authorized'=>true]]);
	$t->same('POST',$request->method());
	$t->same('yes',$request->header('x-base'));
	$t->same('cookie',$request->cookie('base'));
	$t->isTrue($request->attribute('dataphyre_api_auth')['authorized']);
})->tag('api','manager','coverage')->group('framework-coverage');

test('api manager response trace cache and identity helpers normalize boundary values',static function(Context $t): void {
	$private=$t->nonPublic(ApiManager::instance());
	$trace=['api_trace_id'=>'trace-1','duration_ms'=>1];
	$options=['enabled'=>true,'header'=>'X-Trace','response_key'=>'trace'];
	$t->same(204,$private->invoke('normalizeExecutionResponse',null,null,$options)->status);
	$t->contains('"data":"value"',$private->invoke('normalizeExecutionResponse','value',null,$options)->body);
	$t->contains('"trace"',$private->invoke('normalizeExecutionResponse',['ok'=>true],$trace,$options)->body);
	$jsonObject=new class implements JsonSerializable {
		public function jsonSerialize(): mixed { return ['ok'=>true]; }
	};
	$t->contains('"data"',$private->invoke('normalizeExecutionResponse',$jsonObject,$trace,$options)->body);
	$t->same(['data'=>[1,2],'meta'=>$trace],$private->invoke('injectTraceIntoPayload',[1,2],$trace,'meta'));
	$t->contains('_trace',array_keys($private->invoke('injectTraceIntoPayload',['trace'=>'existing'],$trace,'trace',)));

	$json=Response::json(['ok'=>true,'trace'=>$trace],200,['X-Trace'=>'trace-1']);
	$stored=$private->invoke('responseForEndpointCacheStorage',$json,$options);
	$t->isFalse(isset($stored->headers['X-Trace']));
	$t->notContains('"trace"', $stored->body);
	$t->isTrue($private->invoke('isEndpointResponseCacheable',Response::json([],500),['store_errors'=>true]));
	$t->isTrue($private->invoke('isEndpointResponseCacheable',Response::json([],201),[]));
	$t->isFalse($private->invoke('isEndpointResponseCacheable',Response::json([],404),[]));
	$t->isTrue($private->invoke('isJsonResponse',$json));
	$t->isFalse($private->invoke('isJsonResponse',new Response('text',200,['Content-Type'=>'text/plain'])));
	$t->same(['A'=>'one'],$private->invoke('selectedRequestValues',['a'=>'one','b'=>'two'],['A']));
	$t->same([],$private->invoke('selectedRequestValues',['a'=>'one','b'=>'two'],null));
	$t->same(['orders','tenant'],$private->invoke('normalizeEndpointCacheNames',[ ' orders ','','tenant','orders']));
	$t->same(['orders'],$private->invoke('normalizeEndpointCacheNames','orders'));
	$t->same('DateTimeImmutable',$private->invoke('normalizeEndpointCacheIdentityValue',new DateTimeImmutable('2026-01-01 UTC')));
	$t->same(['a'=>1],$private->invoke('normalizeEndpointCacheIdentityValue',['a'=>1]));
	$t->same(['b'=>'2','a'=>'1'],$private->invoke('withoutHeaderCaseInsensitive',['b'=>'2','X-Trace'=>'remove','a'=>'1'],'x-trace',));
	$t->same(['value'=>'x'],$private->invoke('normalizeBindingCacheIdentity',['value'=>'x']));
	$t->same(null,$private->invoke('normalizeBindingCacheIdentity',null));
	$t->same('DateTimeImmutable',$private->invoke('normalizeBindingCacheIdentityValue',new DateTimeImmutable('2026-01-01 UTC')));
	$t->same('Mobile V1',$private->invoke('normalizeProfileName',' Mobile V1 '));
	$t->same(null,$private->invoke('normalizeProfileName',new stdClass()));
	$t->same('POST',$private->invoke('inferInternalDispatchMethod',null,'create',['value'=>1]));
	$t->same('GET',$private->invoke('inferInternalDispatchMethod',null,'GET/show',['body'=>'ignored']));
	$t->same('DELETE',$private->invoke('inferAliasMethod','DELETE/orders'));
	$t->same(null,$private->invoke('inferAliasMethod','custom'));
	$t->notEmpty($private->invoke('newTraceId'));
	$traceOptions=$private->invoke('normalizeTraceOptions',['response_key'=>'','header'=>'']);
	$t->same('trace',$traceOptions['response_key']);
	$t->same('X-Dataphyre-Api-Trace',$traceOptions['header']);
	$t->same('https://api.example.test',$private->invoke('defaultServers',['https://api.example.test'])[0]);
})->tag('api','manager','coverage')->group('framework-coverage');

test('api manager matched dispatch supports execution callables controllers and failures',static function(Context $t): void {
	$private=$t->nonPublic(ApiManager::instance());
	$request=Request::create('GET','/api/test',[],[],[],[],[],['id'=>'7']);
	$middleware=$private->invoke('dispatchMatchedRoute',[
		'middleware'=>['auth'],'parameters'=>[],
	],$request);
	$t->same(501,$middleware->status);

	$compiled=\Dataphyre\Api\Endpoint::get('/api/compiled',static fn(): array=>['compiled'=>true])->compile();
	$t->same(200,$private->invoke('dispatchMatchedRoute',$compiled,Request::create('GET','/api/compiled'))->status);
	$callableRoute=['handler'=>[
		'type'=>'callable','target'=>static fn(array $parameters,array $route): array=>['kind'=>'callable','id'=>$parameters['id'] ?? null],
	],'parameters'=>['id'=>7]];
	$t->contains('callable',$private->invoke('dispatchMatchedRoute',$callableRoute,$request)->body);
	$t->contains('direct',$private->invoke('dispatchMatchedRoute',[
		'handler'=>static fn(array $parameters,array $route): array=>['kind'=>'direct'],
		'parameters'=>[],
	],$request)->body);
	$t->same(501,$private->invoke('dispatchMatchedRoute',['handler'=>'missing'],$request)->status);

	$static=['handler'=>[
		'type'=>'controller','class'=>DpApiManagerController::class,'method'=>'staticHandle','static'=>true,
	],'parameters'=>['id'=>7]];
	$t->contains('static',$private->invoke('dispatchMatchedRoute',$static,$request)->body);
	$instance=['handler'=>[
		'type'=>'controller','class'=>DpApiManagerController::class,'method'=>'instanceHandle','static'=>false,
	],'parameters'=>[]];
	$t->contains('instance',$private->invoke('dispatchMatchedRoute',$instance,$request)->body);
	$t->throws(static fn()=>$private->invoke('invokeInternalController',['class'=>'','method'=>''],$static,$request,),RuntimeException::class);
	$t->same(null,$private->invoke('bootstrapCompiledHandler','invalid'));
	$t->same(null,$private->invoke('bootstrapCompiledHandler',[]));
	$t->throws(static fn()=>$private->invoke('bootstrapCompiledHandler',[
		'bootstrap'=>'C:/missing/bootstrap.php',
	]),RuntimeException::class);
	foreach([
		'Dataphyre\\Access\\Thing'=>['access'],'Dataphyre\\Api\\Thing'=>['api'],
		'Dataphyre\\Currency\\Thing'=>['currency'],'Dataphyre\\Database\\Thing'=>['sql'],
		'Dataphyre\\FulltextEngine\\Thing'=>['fulltext_engine'],'Dataphyre\\Http\\Thing'=>['http'],
		'Dataphyre\\Routing\\Thing'=>['routing'],'Dataphyre\\Sanitation\\Thing'=>['sanitation'],
		'App\\Thing'=>[],
	] as $class=>$modules){
		$t->same($modules,$private->invoke('inferFrameworkModulesForClass',$class));
	}

	$definition=[
		'key'=>'GET /api/test','method'=>'GET','path'=>'/api/test','alias'=>null,'profile'=>null,
	];
	$route=['path_template'=>'/api/test','api'=>[
		'path'=>'/api/test','aliases'=>['test.show'],'operation_id'=>'test.show','profile'=>['name'=>'v1'],
	]];
	$row=$private->invoke('normalizeDispatchedResponse',$definition,$route,Response::json(['ok'=>true],201),microtime(true)-0.01);
	$t->isTrue($row['ok']);
	$t->same(201,$row['status']);
	$t->same(true,$row['json']['ok']);
	$t->same(null,$private->invoke('decodeJsonResponse',new Response('text',200,['Content-Type'=>'text/plain'])));
	$t->same(null,$private->invoke('decodeJsonResponse',new Response('{bad',200,['Content-Type'=>'application/json'])));
	$t->same(['ok'=>true],$private->invoke('decodeJsonResponse',Response::json(['ok'=>true])));

	$routes=[
		['methods'=>['GET'],'exact_path'=>'/api/exact','api'=>['aliases'=>['exact.show']]],
		['methods'=>['GET'],'path_template'=>'/api/items/{id}','api'=>['aliases'=>['items.show'],'profile'=>['name'=>'v1']]],
	];
	$pathDefinition=[
		'method'=>'GET','path'=>'/api/exact','alias'=>null,'profile'=>null,
		'query'=>[],'body'=>[],'route_parameters'=>[],'headers'=>[],'cookies'=>[],'server'=>[],'attributes'=>[],
	];
	$dispatch=$private->capture('resolveManifestDispatch',routes:$routes,definition:$pathDefinition);
	$t->hasPath(['route'],$dispatch->result());
	$pathDefinition=$dispatch->argument('definition');
	$missing=$pathDefinition;
	$missing['path']='/missing';
	$dispatch=$private->capture('resolveManifestDispatch',routes:$routes,definition:$missing);
	$t->same(404,$dispatch->result()['status']);
	$alias=[
		'method'=>'GET','path'=>null,'alias'=>'items.show','profile'=>'v1',
		'query'=>['id'=>9],'body'=>[],'route_parameters'=>[],
		'headers'=>[],'cookies'=>[],'server'=>[],'attributes'=>[],
	];
	$dispatch=$private->capture('resolveManifestDispatch',routes:$routes,definition:$alias);
	$t->hasPath(['route'],$dispatch->result());
	$alias=$dispatch->argument('definition');
	$t->same('/api/items/9',$alias['path']);
	$noAlias=$alias;
	$noAlias['path']=null;
	$noAlias['alias']='';
	$dispatch=$private->capture('resolveManifestDispatch',routes:$routes,definition:$noAlias);
	$t->same(422,$dispatch->result()['status']);
	$unknown=$alias;
	$unknown['path']=null;
	$unknown['alias']='unknown';
	$dispatch=$private->capture('resolveManifestDispatch',routes:$routes,definition:$unknown);
	$t->same(404,$dispatch->result()['status']);
	$missingParameter=$alias;
	$missingParameter['path']=null;
	$missingParameter['query']=[];
	$dispatch=$private->capture('resolveManifestDispatch',routes:$routes,definition:$missingParameter);
	$t->same(422,$dispatch->result()['status']);
})->tag('api','manager','coverage')->group('framework-coverage');

test('api manager endpoint cache stores hits varies identity and invalidates names',static function(Context $t): void {
	$state=$t->state('api.manager.deep',['cache_calls'=>0]);
	$manager=ApiManager::instance();
	$name='api-manager-deep-cache';
	$t->defer(static function()use($manager,$name): void {
		$manager->clearEndpointCache($name);
		$manager->clearEndpointCache('shared-cache');
	});
	$manager->clearEndpointCache($name);
	$route=\Dataphyre\Api\Endpoint::get('/api/cache')
		->cache(120,[
			'names'=>[$name,'shared-cache'],
			'methods'=>['GET'],
			'query'=>['page'],
			'headers'=>['X-Tenant'],
			'cookies'=>['session'],
		])
		->execute(DpApiManagerCacheTarget::class.'::handle')
		->compile();
	$request=Request::create('GET','/api/cache',['page'=>1],[],['session'=>'abc'],[],['X-Tenant'=>'42']);
	$first=$manager->executeCompiledRoute($route,$request);
	$t->same(200,$first?->status);
	$t->contains('"calls":1',$first?->body ?? '');
	$second=$manager->executeCompiledRoute($route,$request);
	$t->contains('"calls":1',$second?->body ?? '');
	$t->same(1,$state->get('cache_calls'));
	$varied=$manager->executeCompiledRoute($route,Request::create(
		'GET','/api/cache',['page'=>2],[],['session'=>'abc'],[],['X-Tenant'=>'42']
	));
	$t->contains('"calls":2',$varied?->body ?? '');
	$t->same(2,$state->get('cache_calls'));
	$t->isTrue($manager->clearEndpointCache($name)>=1);
	$afterClear=$manager->executeCompiledRoute($route,$request);
	$t->contains('"calls":3',$afterClear?->body ?? '');

	$uncached=\Dataphyre\Api\Endpoint::post('/api/cache-post')
		->cache(60,['methods'=>['GET'],'names'=>[$name]])
		->execute(DpApiManagerCacheTarget::class.'::handle')->compile();
	$manager->executeCompiledRoute($uncached,Request::create('POST','/api/cache-post',[],['value'=>1]));
	$manager->executeCompiledRoute($uncached,Request::create('POST','/api/cache-post',[],['value'=>1]));
	$t->same(4,$state->get('cache_calls'));

	$errorRoute=\Dataphyre\Api\Endpoint::get('/api/cache-error')
		->cache(60,['names'=>[$name],'store_errors'=>false])
		->execute(DpApiManagerCacheTarget::class.'::error')->compile();
	$manager->executeCompiledRoute($errorRoute,Request::create('GET','/api/cache-error'));
	$manager->executeCompiledRoute($errorRoute,Request::create('GET','/api/cache-error'));
	$t->same(6,$state->get('cache_calls'));
	$manager->clearEndpointCache($name);
	$manager->clearEndpointCache('shared-cache');
})->tag('api','manager','coverage')->group('framework-coverage');

test('api manager callable bindings resolve nested paths identities and invalid definitions',static function(Context $t): void {
	$private=$t->nonPublic(ApiManager::instance());
	$state=$t->state('api.manager.deep',['binding_calls'=>0]);
	$route=\Dataphyre\Api\Endpoint::get('/api/bindings')
		->withBinding('first',DpApiManagerController::class.'::binding',['key'=>'shared'])
		->withBinding('nested.value',DpApiManagerController::class.'::binding',['key'=>'shared'])
		->execute(DpApiManagerController::class.'::apiHandle')
		->compile();
	$response=ApiManager::instance()->executeCompiledRoute($route,Request::create('GET','/api/bindings'));
	$t->same(200,$response?->status);
	$t->contains('"nested"', $response?->body ?? '');
	$t->same(2,$state->get('binding_calls'));
	$t->throws(static fn()=>$private->invoke('bindingFromDefinition','bad',['type'=>'unsupported']),RuntimeException::class);
	$t->throws(static fn()=>$private->invoke('callableBindingFromDefinition','bad',['type'=>'callable']),RuntimeException::class);
	$t->throws(static fn()=>$private->invoke('callableBindingFromDefinition','bad',[
		'type'=>'callable','target'=>['type'=>'class_method','class'=>'Missing\\Target','method'=>'run'],
	]),RuntimeException::class);
	$binding=$private->invoke('callableBindingFromDefinition','good',[
		'type'=>'callable',
		'target'=>['type'=>'class_method','class'=>DpApiManagerController::class,'method'=>'binding','static'=>true],
		'identity'=>['key'=>'binding'],
	]);
	$t->instanceOf(\Dataphyre\Templating\DataBinding::class,$binding);
	$context=new \Dataphyre\Api\ApiContext(Request::create('GET','/api/bindings'),$route);
	$bindingContext=$private->invoke('bindingContextForApi',$context,['first'=>['ok'=>true]],'nested.value',[],2);
	$t->instanceOf(\Dataphyre\Templating\BindingContext::class,$bindingContext);
	$t->notEmpty($private->invoke('resolveApiBindingWithTraceContext',$binding,$bindingContext,[],[],'good'));
	$t->same(DpApiManagerController::class.'::binding',$private->invoke('callableTargetLabel',[
		'type'=>'class_method','class'=>DpApiManagerController::class,'method'=>'binding',
	]));
	$t->same('reference',$private->invoke('callableTargetLabel',[
		'type'=>'callable','reference'=>'reference',
	]));
	$t->same(null,$private->invoke('callableTargetLabel',['type'=>'other']));
	$t->same('array',$private->invoke('bindingResultType',[]));
	$t->same(stdClass::class,$private->invoke('bindingResultType',new stdClass()));
	$t->same('int',$private->invoke('bindingResultType',1));

	$t->same(['key'=>'identity'],$private->invoke('normalizeBindingCacheIdentity',' identity '));
	$t->same(null,$private->invoke('normalizeBindingCacheIdentity',''));
	$t->same(['value'=>7],$private->invoke('normalizeBindingCacheIdentity',7));
	$t->same(['a'=>1,'b'=>2],$private->invoke('normalizeBindingCacheIdentity',['b'=>2,'a'=>1]));
	$stringable=new class implements Stringable { public function __toString(): string { return 'stringable'; } };
	$t->same(['value'=>'stringable'],$private->invoke('normalizeBindingCacheIdentity',$stringable));
	$t->same(['value_type'=>stdClass::class],$private->invoke('normalizeBindingCacheIdentity',new stdClass()));
	$t->same('stringable',$private->invoke('normalizeBindingCacheIdentityValue',$stringable));
	$t->same(stdClass::class,$private->invoke('normalizeBindingCacheIdentityValue',new stdClass()));

	$target=[];
	$mutation=$private->capture('setArrayValueByPath',target:$target,path:'nested.value',value:7);
	$target=$mutation->argument('target');
	$mutation=$private->capture('setArrayValueByPath',target:$target,path:'plain',value:8);
	$target=$mutation->argument('target');
	$mutation=$private->capture('setArrayValueByPath',target:$target,path:'nested.other.deep',value:9);
	$target=$mutation->argument('target');
	$t->same(7,$target['nested']['value']);
	$t->same(8,$target['plain']);
	$t->same(9,$target['nested']['other']['deep']);
})->tag('api','manager','coverage')->group('framework-coverage');
