<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Api\ApiContext;
use Dataphyre\Api\OpenApiGenerator;
use Dataphyre\Api\SecurityScheme;
use Dataphyre\Http\Request;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

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
$dp_api_support_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_api_support_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_api_support_modules_root);
\dataphyre\autoloader::register_framework_modules(['api', 'http', 'routing', 'sanitation', 'templating']);
if(!class_exists(\dataphyre\sanitation::class, false)){
	require_once $dp_api_support_modules_root.'/sanitation/unit_tests/sanitation_test_helpers.php';
}

test('api context covers complete request surfaces caches auth fallbacks and nested dispatch helpers', static function(Context $t): void {
	$request=Request::create(
		'POST',
		'/api/support/9',
		['page'=>'2', 'nested'=>['query'=>'yes']],
		['name'=>'Ada', 'nested'=>['body'=>'yes']],
		['session'=>'cookie'],
		['SERVER_NAME'=>'api.example.test'],
		['X-Tenant'=>'42'],
		['id'=>'9', 'nested'=>'scalar'],
		['dataphyre_api_auth'=>[
			'authorized'=>true,
			'scheme'=>' supportKey ',
			'identity'=>null,
			'scopes'=>['read'],
			'context'=>'malformed-context',
			'meta'=>'malformed-meta',
		]]
	);
	$context=new ApiContext($request, [
		'path_template'=>'/api/support/{id}',
		'api'=>['dispatch'=>['continue_on_error'=>true]],
	]);
	$private=$t->nonPublic($context);

	$t->same(['id'=>'9', 'nested'=>'scalar'], $context->parameters());
	$t->same('missing', $context->parameters('nested.value', 'missing'));
	$t->same($context->all(), $context->input());
	$t->same($request->headers(), $context->header());
	$t->producesStableResult(static fn()=>$context->all('query'));
	$t->same('cookie', $context->all(['cookies'])['session']);
	$t->same($request->headers(), $context->all(['headers']));
	$t->same('api.example.test', $context->all(['server'])['SERVER_NAME']);
	$t->same('Ada', $context->all([' ', 'unsupported'])['name']);

	$t->same([],$private->invoke('mergeSources',['unsupported']));
	$t->same(['query', 'body', 'route'],$private->invoke('normalizeSources',null));

	$validation=$context->validate(['name'=>'required|name'], [], ['sources'=>['body']]);
	$t->same($context, $context->withValidationResult($validation));
	$t->same($validation, $context->validation());
	$t->same([], $context->authContext());
	$t->same('fallback', $context->authContext('tenant', 'fallback'));
	$t->same([], $context->authMeta());
	$t->same('fallback', $context->authMeta('source', 'fallback'));

	$dispatch=$context->dispatch(['path'=>'/api/support/missing', 'method'=>'GET']);
	$t->isFalse($dispatch['ok']);
	$t->same(404, $dispatch['status']);
	$batch=$context->dispatchBatch([['path'=>'/api/support/missing', 'method'=>'GET']]);
	$t->isFalse($batch['ok']);
	$t->same(1, $batch['count']);
	$chain=$context->dispatchChain([['path'=>'/api/support/missing', 'method'=>'GET']]);
	$t->isFalse($chain['ok']);
})->tag('api', 'support', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('security schemes cover documentation runtimes resolver validation and normalization caches', static function(Context $t): void {
	$described=SecurityScheme::guard('sessionAuth', [' session ', '', 'access'], [], [
		'description'=>' Session security ',
		'scopes'=>[' ', 'read', 'read', ' write '],
	]);
	$t->same('Session security', $described->toArray()['openapi']['description']);
	$t->same(['read', 'write'], $described->scopes());

	$guardRuntime=SecurityScheme::oauth2('oauthGuard', [], [
		'guard'=>['jwt', '', 123],
		'failure_status'=>403,
		'failure_message'=>'Guard denied.',
	]);
	$t->same([
		'type'=>'guard',
		'guards'=>['jwt'],
		'failure_status'=>403,
		'failure_message'=>'Guard denied.',
	], $guardRuntime->toArray()['runtime']);

	$resolverRuntime=SecurityScheme::openIdConnect('oidcCallback', 'https://example.test/.well-known/openid-configuration', [
		'resolver'=>' SupportResolver::authorize ',
		'failure_status'=>429,
		'failure_message'=>'Callback denied.',
	]);
	$t->same('SupportResolver::authorize', $resolverRuntime->toArray()['runtime']['resolver']);
	$t->same('callback', $resolverRuntime->toArray()['runtime']['type']);

	$custom=SecurityScheme::custom('customRuntime', ['type'=>'http', 'scheme'=>'custom'], [
		'resolver'=>['\\SupportResolver', ' authorize '],
		'guards'=>[' session ', '', 'access'],
	], ['scopes'=>[' ', 'custom']]);
	$t->same(['SupportResolver', 'authorize'], $custom->toArray()['runtime']['resolver']);
	$t->same(['session', 'access'], $custom->toArray()['runtime']['guards']);
	$t->same(['custom'], $custom->scopes());

	$t->same(null, SecurityScheme::bearer('nullableResolver')->toArray()['runtime']['resolver']);
	$cachedGuards=[' jwt ', '', 'access'];
	$t->same(
		SecurityScheme::guard('cacheOne', $cachedGuards)->toArray()['runtime']['guards'],
		SecurityScheme::guard('cacheTwo', $cachedGuards)->toArray()['runtime']['guards']
	);
	$t->throws(
		static fn()=>SecurityScheme::bearer('invalidResolver', ['resolver'=>[]]),
		InvalidArgumentException::class
	);
})->tag('api', 'support', 'coverage')->group('framework-coverage');

test('OpenAPI generator covers invalid rows metadata fallbacks duplicates and operation normalization', static function(Context $t): void {
	$generator=new OpenApiGenerator();
	$document=$generator->generate([
		'not-an-endpoint',
		[
			'path'=>'/support/{id}',
			'methods'=>[' get ', 'GET', 'post', 'trace'],
			'summary'=>' Support endpoint ',
			'description'=>' Detailed support endpoint ',
			'operation_id'=>'support.show',
			'tags'=>['Support'],
			'deprecated'=>true,
			'parameters'=>[['name'=>'id', 'in'=>'path', 'required'=>true]],
			'request_body'=>['content'=>['application/json'=>['schema'=>['type'=>'object']]]],
			'responses'=>[
				201=>'Created',
				400=>[],
			],
			'security'=>[['supportKey'=>[]]],
			'security_schemes'=>[
				'supportKey'=>['openapi'=>['type'=>'apiKey', 'in'=>'header', 'name'=>'X-Support-Key']],
				'empty'=>['openapi'=>[]],
				'invalid'=>['openapi'=>'invalid'],
			],
			'servers'=>[
				'https://endpoint.example.test',
				['url'=>'https://regional.example.test', 'description'=>'Regional'],
			],
		],
		[
			'path'=>'/duplicate',
			'methods'=>['HEAD'],
			'security_schemes'=>[
				'supportKey'=>['openapi'=>['type'=>'http', 'scheme'=>'bearer']],
			],
		],
	], [
		'title'=>' Support API ',
		'version'=>' 2.0.0 ',
		'description'=>' API description ',
		'termsOfService'=>' https://example.test/terms ',
		'contact'=>['name'=>'Support'],
		'license'=>['name'=>'MIT'],
		'servers'=>[null, '', [], ['url'=>''], 123],
	]);

	$t->same('Support API', $document['info']['title']);
	$t->same('2.0.0', $document['info']['version']);
	$t->same('API description', $document['info']['description']);
	$t->same('https://example.test/terms', $document['info']['termsOfService']);
	$t->same(['name'=>'Support'], $document['info']['contact']);
	$t->same(['name'=>'MIT'], $document['info']['license']);
	$t->same('https://endpoint.example.test', $document['servers'][0]['url']);
	$t->hasPath(['paths', '/support/{id}', 'get'], $document);
	$t->hasPath(['paths', '/support/{id}', 'post'], $document);
	$t->isFalse(isset($document['paths']['/support/{id}']['trace']));
	$t->same('Created', $document['paths']['/support/{id}']['get']['responses']['201']['description']);
	$t->same('Response', $document['paths']['/support/{id}']['get']['responses']['400']['description']);
	$t->same('apiKey', $document['components']['securitySchemes']['supportKey']['type']);
	$t->same(1, count($document['components']['securitySchemes']));
	$t->same(['200'=>['description'=>'OK']], $document['paths']['/duplicate']['head']['responses']);
})->tag('api', 'support', 'coverage')->group('framework-coverage');
