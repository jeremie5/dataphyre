<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Api\Api;
use Dataphyre\Api\ApiContext;
use Dataphyre\Api\ApiManager;
use Dataphyre\Api\Endpoint;
use Dataphyre\Api\OpenApiGenerator;
use Dataphyre\Api\SecurityScheme;
use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('API framework coverage')->sandboxesRootpath('api_cache');

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>[
			'api'=>true,
			'core'=>true,
			'http'=>true,
			'routing'=>true,
			'sanitation'=>true,
			'templating'=>true,
		],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_api_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_api_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_api_modules_root);
\dataphyre\autoloader::register_framework_modules(['api', 'http', 'routing', 'sanitation', 'templating']);
if(!class_exists(\dataphyre\sanitation::class, false)){
	require_once $dp_api_modules_root.'/sanitation/unit_tests/sanitation_test_helpers.php';
}

final class DpApiCoverageEndpoint {
	public static function authorize(mixed $credentials): array {
		$authorized=$credentials===null || in_array($credentials, ['secret', 'Bearer secret', ['username'=>'ada', 'password'=>'secret']], true);
		return $authorized
			? ['authorized'=>true, 'identity'=>['id'=>7], 'context'=>['tenant'=>42], 'meta'=>['source'=>'coverage']]
			: ['authorized'=>false, 'status'=>403, 'message'=>'Credential denied.', 'headers'=>['X-Auth'=>'denied']];
	}

	public static function binding(ApiContext $context): array {
		return ['name'=>$context->validated('name'), 'path'=>$context->path()];
	}

	public static function before(ApiContext $context): null {
		$context->withBindings(['before'=>true]);
		return null;
	}

	public static function after(ApiContext $context, mixed $result, Response $response): Response {
		return Response::json(['ok'=>true, 'result'=>$result, 'after'=>true, 'binding'=>$context->binding('summary')], $response->status);
	}

	public static function onError(ApiContext $context, Throwable $exception): Response {
		return Response::json(['ok'=>false, 'caught'=>$exception->getMessage()], 409);
	}

	public static function handle(ApiContext $context): array {
		return [
			'ok'=>true,
			'name'=>$context->validated('name'),
			'route_id'=>$context->parameters('id'),
			'summary'=>$context->binding('summary'),
		];
	}

	public static function throws(ApiContext $context): never {
		throw new RuntimeException('endpoint exploded');
	}

	public static function response(ApiContext $context): Response {
		return Response::json(['ok'=>true, 'kind'=>'response'], 201, ['X-Result'=>'response']);
	}
}

test('api endpoint DSL compiles discovery OpenAPI security bindings lifecycle and cache metadata', static function(Context $t): void {
	$query=new class {
		public function executionState(): array { return ['table'=>'coverage', 'spec'=>[]]; }
		public function fingerprint(): string { return 'query-fingerprint'; }
	};
	$search=new class {
		public function executionState(): array { return ['index'=>'coverage']; }
		public function fingerprint(): string { return 'search-fingerprint'; }
	};
	$apiKey=SecurityScheme::apiKey('keyAuth', 'X-Api-Key', 'header', [
		'resolver'=>[DpApiCoverageEndpoint::class, 'authorize'],
		'scopes'=>['read', 'write', 'read'],
		'description'=>'Coverage key',
	]);
	$bearer=SecurityScheme::bearer('bearerAuth', ['resolver'=>[DpApiCoverageEndpoint::class, 'authorize'], 'bearer_format'=>'JWT']);
	$basic=SecurityScheme::basic('basicAuth', ['resolver'=>[DpApiCoverageEndpoint::class, 'authorize']]);
	$oauth=SecurityScheme::oauth2('oauth', ['authorizationCode'=>['authorizationUrl'=>'https://example.test/auth', 'tokenUrl'=>'https://example.test/token', 'scopes'=>[]]]);
	$openid=SecurityScheme::openIdConnect('oidc', 'https://example.test/.well-known/openid-configuration');
	$custom=SecurityScheme::custom('custom', ['type'=>'apiKey', 'in'=>'query', 'name'=>'token'], ['type'=>'callback', 'resolver'=>[DpApiCoverageEndpoint::class, 'authorize']], ['scopes'=>'read']);
	$guard=SecurityScheme::guard('guards', ['session', '', 'access'], [], ['scopes'=>'read']);
	$jwt=SecurityScheme::jwtGuard('jwt', 'jwtAuth');
	foreach([$apiKey, $bearer, $basic, $oauth, $openid, $custom, $guard, $jwt] as $scheme){
		$t->notEmpty($scheme->name());
		$t->isTrue(is_array($scheme->scopes()));
		$t->producesStableResult(static fn()=>$scheme->toArray());
	}

	$endpoint=Endpoint::methods(['get', 'GET', 'head'], 'api/orders/{id}')
		->middleware('api', ['tenant'])
		->tag('Orders', ['Internal', 'Orders'], '')
		->alias('/get/orders/show/')
		->aliases('orders.show', ['', 'legacy/orders/show'])
		->summary(' Show order ')
		->description(' Order detail endpoint ')
		->operationId('orders.show')
		->deprecated()
		->pathParameter('id', ['type'=>'integer'], ['description'=>'Order id'])
		->queryParameter('include', ['type'=>'string'], ['required'=>false, 'example'=>'items'])
		->headerParameter('X-Tenant', ['type'=>'string'])
		->cookieParameter('session', ['type'=>'string'])
		->parameter('ignored', 'unsupported', [], ['style'=>'form', 'explode'=>true, 'allowEmptyValue'=>true])
		->jsonBody(['type'=>'object', 'properties'=>['name'=>['type'=>'string']]], true, 'Payload')
		->requestBody(['text/plain'=>['schema'=>['type'=>'string']]])
		->jsonResponse(200, ['type'=>'object'], 'Order')
		->response('default', ['description'=>'Error'])
		->auth($apiKey, $bearer)
		->authAll($apiKey, $custom)
		->server('https://api.example.test', 'Production')
		->withBinding('summary', DpApiCoverageEndpoint::class.'::binding', ['identity'=>['tenant'=>42]])
		->withBindings([
			'extra'=>['target'=>DpApiCoverageEndpoint::class.'::binding', 'identity'=>'extra'],
			'plain'=>DpApiCoverageEndpoint::class.'::binding',
			''=>DpApiCoverageEndpoint::class.'::binding',
		])
		->withQuery('rows', $query, 'records', ['columns'=>['id']])
		->withQueryIdentity('rows_identity', $query, 'rows')
		->withSearch('results', $search, 'results')
		->withSearchIdentity('results_identity', $search, 'hits')
		->schema(['name'=>'required|name'], [], ['sources'=>['body'], 'status'=>422])
		->withTrace(true, ['include_headers'=>true, 'response_header'=>'X-Api-Trace'])
		->cache(60, ['names'=>['orders', 'tenant:42'], 'methods'=>['GET']])
		->profile('mobile.v1', ['aliases'=>['legacy']])
		->dispatchDefaults(['trust_auth'=>false])
		->beforeExecute(DpApiCoverageEndpoint::class.'::before')
		->afterExecute(DpApiCoverageEndpoint::class.'::after')
		->onError(DpApiCoverageEndpoint::class.'::onError')
		->execute(DpApiCoverageEndpoint::class.'::handle');
	$compiled=$endpoint->compile();
	$t->same('/api/orders/{id}', $compiled['api']['path']);
	$t->same(['GET', 'HEAD'], $compiled['api']['methods']);
	$t->contains('Orders', $compiled['api']['tags']);
	$t->contains('get/orders/show', $compiled['api']['aliases']);
	$t->same('orders.show', $compiled['api']['operation_id']);
	$t->isTrue($compiled['api']['deprecated']);
	$t->notEmpty($compiled['api']['parameters']);
	$t->notEmpty($compiled['api']['security_schemes']);
	$t->notEmpty($compiled['api']['bindings']);
	$t->notEmpty($compiled['api']['lifecycle']);

	$manager=ApiManager::instance();
	$t->instanceOf(ApiManager::class, $manager);
	$discovered=$manager->discoverManifest(['routes'=>[null, ['methods'=>['GET']], $compiled]]);
	$t->same(1, count($discovered));
	$t->same('/api/orders/{id}', $discovered[0]['path']);
	$document=(new OpenApiGenerator())->generate($discovered, [
		'title'=>'Coverage API',
		'version'=>'1.2.3',
		'servers'=>['https://api.example.test', ['url'=>'https://staging.example.test', 'description'=>'Staging']],
	]);
	$t->same('3.1.0', $document['openapi']);
	$t->same('Coverage API', $document['info']['title']);
	$t->hasPath(['paths', '/api/orders/{id}', 'get'], $document);
	$t->hasPath(['components', 'securitySchemes', 'keyAuth'], $document);

	$docs=$manager->documentationRoutes([
		'docs_path'=>'docs/api/',
		'spec_path'=>'docs/api/openapi.json',
		'asset_path'=>'docs/api/assets/',
		'title'=>'Coverage API',
		'servers'=>['https://api.example.test'],
	]);
	$t->same(3, count($docs));
	$t->same('/docs/api/openapi.json', $docs[0]['path_template']);
	$t->same('/docs/api', $docs[1]['path_template']);
	$t->same('/docs/api/assets/{asset}', $docs[2]['path_template']);

	$t->throws(static fn()=>Endpoint::get('/missing')->compile(), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/bad', static fn()=>null)->alias(''), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/bad')->withBinding('', DpApiCoverageEndpoint::class.'::binding'), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/bad')->withQuery('', $query), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/bad')->withSearch('', $search), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/bad')->profile(''), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/bad')->execute(new stdClass()), RuntimeException::class);
})->tag('api', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('api authorization covers API key bearer basic custom docs and malformed requirements', static function(Context $t): void {
	$manager=ApiManager::instance();
	$t->same(null, $manager->authorizeCompiledRoute([], Request::create('GET', '/')));
	$t->same(null, $manager->authorizeCompiledRoute(['api'=>[]], Request::create('GET', '/')));

	$schemes=[
		SecurityScheme::apiKey('headerKey', 'X-Api-Key', 'header', ['resolver'=>[DpApiCoverageEndpoint::class, 'authorize']]),
		SecurityScheme::apiKey('queryKey', 'api_key', 'query', ['resolver'=>[DpApiCoverageEndpoint::class, 'authorize']]),
		SecurityScheme::apiKey('cookieKey', 'api_key', 'cookie', ['resolver'=>[DpApiCoverageEndpoint::class, 'authorize']]),
		SecurityScheme::bearer('bearer', ['resolver'=>[DpApiCoverageEndpoint::class, 'authorize']]),
		SecurityScheme::basic('basic', ['resolver'=>[DpApiCoverageEndpoint::class, 'authorize']]),
		SecurityScheme::custom('callback', ['type'=>'http', 'scheme'=>'custom'], ['type'=>'callback', 'resolver'=>[DpApiCoverageEndpoint::class, 'authorize']]),
		SecurityScheme::oauth2('docs', ['clientCredentials'=>['tokenUrl'=>'https://example.test/token', 'scopes'=>[]]]),
	];
	$requests=[
		Request::create('GET', '/', [], [], [], [], ['X-Api-Key'=>'secret']),
		Request::create('GET', '/', ['api_key'=>'secret']),
		Request::create('GET', '/', [], [], ['api_key'=>'secret']),
		Request::create('GET', '/', [], [], [], [], ['Authorization'=>'Bearer secret']),
		Request::create('GET', '/', [], [], [], [], ['Authorization'=>'Basic '.base64_encode('ada:secret')]),
		Request::create('GET', '/'),
		Request::create('GET', '/'),
	];
	foreach($schemes as $index=>$scheme){
		$compiled=$scheme->toArray();
		$route=['path_template'=>'/secure', 'api'=>[
			'security'=>[[$scheme->name()=>[]]],
			'security_schemes'=>[$scheme->name()=>$compiled],
		]];
		$response=$manager->authorizeCompiledRoute($route, $requests[$index]);
		if($scheme->name()==='docs'){
			$t->instanceOf(Response::class, $response);
			$t->same(401, $response->status);
		}else{
			$t->same(null, $response);
		}
	}

	$denied=SecurityScheme::apiKey('denied', 'X-Api-Key', 'invalid', ['resolver'=>[DpApiCoverageEndpoint::class, 'authorize']]);
	$route=['api'=>['security'=>[['denied'=>[]]], 'security_schemes'=>['denied'=>$denied->toArray()]]];
	$response=$manager->authorizeCompiledRoute($route, Request::create('GET', '/', [], [], [], [], ['X-Api-Key'=>'wrong']));
	$t->instanceOf(Response::class, $response);
	$t->same(403, $response->status);
	$t->same('denied', $response->headers['X-Auth'] ?? null);
	$t->instanceOf(Response::class, $manager->authorizeCompiledRoute(['api'=>['security'=>[['missing'=>[]]], 'security_schemes'=>['other'=>[]]]], Request::create('GET', '/')));
})->tag('api', 'coverage')->group('framework-coverage');

test('api compiled execution validates binds traces lifecycle normalizes and handles errors', static function(Context $t): void {
	$manager=ApiManager::instance();
	$endpoint=Endpoint::post('/api/orders/{id}')
		->schema(['name'=>'required|name'], [], ['sources'=>['body'], 'status'=>422, 'message'=>'Invalid payload'])
		->withBinding('summary', DpApiCoverageEndpoint::class.'::binding')
		->beforeExecute(DpApiCoverageEndpoint::class.'::before')
		->afterExecute(DpApiCoverageEndpoint::class.'::after')
		->withTrace(true, ['header'=>'X-Api-Trace', 'include_response'=>true])
		->execute(DpApiCoverageEndpoint::class.'::handle');
	$route=$endpoint->compile();
	$request=Request::create('POST', '/api/orders/7', [], ['name'=>'Ada'], [], [], ['Accept'=>'application/json'], ['id'=>'7']);
	$response=$manager->executeCompiledRoute($route, $request);
	$t->instanceOf(Response::class, $response);
	$t->same(200, $response->status);
	$t->contains('"after":true', $response->body);
	$t->contains('"name":"Ada"', $response->body);
	$t->notEmpty($response->headers['X-Api-Trace'] ?? '');

	$invalid=$manager->executeCompiledRoute($route, Request::create('POST', '/api/orders/7', [], [], [], [], [], ['id'=>'7']));
	$t->instanceOf(Response::class, $invalid);
	$t->same(422, $invalid->status);
	$t->contains('Invalid payload', $invalid->body);

	$responseRoute=Endpoint::get('/response')->execute(DpApiCoverageEndpoint::class.'::response')->compile();
	$response=$manager->executeCompiledRoute($responseRoute, Request::create('GET', '/response'));
	$t->same(201, $response?->status);
	$t->same('response', $response?->headers['X-Result'] ?? null);

	$errorRoute=Endpoint::get('/error')
		->onError(DpApiCoverageEndpoint::class.'::onError')
		->execute(DpApiCoverageEndpoint::class.'::throws')
		->compile();
	$error=$manager->executeCompiledRoute($errorRoute, Request::create('GET', '/error'));
	$t->same(409, $error?->status);
	$t->contains('endpoint exploded', $error?->body ?? '');
	$t->same(null, $manager->executeCompiledRoute([], Request::create('GET', '/')));
	$t->same(null, $manager->executeCompiledRoute(['api'=>[]], Request::create('GET', '/')));
})->tag('api', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('api context and batch dispatch cover input auth binding validation and failure records', static function(Context $t): void {
	$request=Request::create(
		'POST', '/api/items/9',
		['page'=>'2', 'nested'=>['query'=>'yes']],
		['name'=>'Ada', 'nested'=>['body'=>'yes']],
		['session'=>'cookie'],
		['SERVER_NAME'=>'example.test'],
		['X-Tenant'=>'42'],
		['id'=>'9'],
		['dataphyre_api_auth'=>['authorized'=>true, 'scheme'=>'keyAuth', 'identity'=>['id'=>7], 'scopes'=>['read'], 'context'=>['tenant'=>42], 'meta'=>['rate'=>'ok']]]
	);
	$context=new ApiContext($request, ['path_template'=>'/api/items/{id}']);
	$t->same($request, $context->request());
	$t->same('/api/items/{id}', $context->route()['path_template']);
	$t->same('POST', $context->method());
	$t->same('/api/items/9', $context->path());
	$t->same('9', $context->parameters('id'));
	$t->same('fallback', $context->parameters('missing', 'fallback'));
	$t->same('2', $context->query('page'));
	$t->same('Ada', $context->body('name'));
	$t->same('Ada', $context->input('name'));
	$t->same('yes', $context->all()['nested']['body']);
	$t->same($context->query(), $context->all('query'));
	$t->same('cookie', $context->cookie('session'));
	$t->same('42', $context->header('x-tenant'));
	$t->same('example.test', $context->server('SERVER_NAME'));
	$validation=$context->validate(['name'=>'required|name'], [], ['sources'=>['body']]);
	$t->isTrue($validation->passed());
	$t->isTrue($context->hasValidatedInput());
	$t->same('Ada', $context->validated('name'));
	$t->same($validation, $context->validation());
	$context->withBindings(['summary'=>['total'=>7]], [['path'=>'summary.total', 'duration_ms'=>1]]);
	$t->isTrue($context->hasBinding('summary.total'));
	$t->same(7, $context->binding('summary.total'));
	$t->notEmpty($context->bindings());
	$t->notEmpty($context->bindingTrace());
	$t->notEmpty($context->bindingData());
	$t->isTrue($context->hasAuth());
	$t->same('keyAuth', $context->authScheme());
	$t->same(7, $context->authIdentity()['id']);
	$t->same(['read'], $context->authScopes());
	$t->same(42, $context->authContext('tenant'));
	$t->same('ok', $context->authMeta('rate'));

	$manager=ApiManager::instance();
	$tooMany=$manager->dispatchBatch([[], []], ['limit'=>1]);
	$t->isFalse($tooMany['ok']);
	$t->same(1, $tooMany['limit']);
	$batch=$manager->dispatchBatch(['named'=>'not-an-array', '/missing'=>['method'=>'GET']], ['continue_on_error'=>true, 'expose_exceptions'=>true]);
	$t->isFalse($batch['ok']);
	$t->same(2, $batch['count']);
	$t->same(2, $batch['failures']);
	$stopped=$manager->dispatchBatch(['bad', []], ['continue_on_error'=>false]);
	$t->same(1, $stopped['count']);
	$chain=$manager->dispatchChain([['path'=>'/missing']]);
	$t->isFalse($chain['ok']);
	$t->same(0, $manager->clearEndpointCache('missing'));
	$t->isTrue($manager->clearEndpointCache()>=0);
	$failure=$manager->dispatch(['path'=>'/missing', 'method'=>'GET'], ['expose_exceptions'=>true]);
	$t->isFalse($failure['ok']);
	$t->same(404, $failure['status']);

	$t->same(ApiManager::instance(), Api::manager());
	$t->same(3, count(Api::documentationRoutes()));
	$t->same(0, count(Api::discoverManifest(['routes'=>[]])));
	$t->isFalse(Api::dispatchBatch([['path'=>'/missing']])['ok']);
	$t->isFalse(Api::dispatchChain([['path'=>'/missing']])['ok']);
})->tag('api', 'coverage')->group('framework-coverage')->maxMillis(10000);
