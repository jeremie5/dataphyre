<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace DataphyreUnitTests;

require_once __DIR__.'/../../routing/Framework/CompilableRoute.php';
require_once __DIR__.'/../../routing/Framework/Route.php';
require_once __DIR__.'/../../http/Framework/Request.php';
require_once __DIR__.'/../../templating/Framework/BindingContext.php';
require_once __DIR__.'/../../templating/Framework/DataBinding.php';
require_once __DIR__.'/../../templating/Framework/BindingMetadataProvider.php';
require_once __DIR__.'/../../templating/Framework/BindingCacheIdentityProvider.php';
require_once __DIR__.'/../Framework/SecurityScheme.php';
require_once __DIR__.'/../Framework/Endpoint.php';
require_once __DIR__.'/../Framework/ApiGroup.php';
require_once __DIR__.'/../Framework/ApiContext.php';
require_once __DIR__.'/../Framework/ApiCallableBinding.php';
require_once __DIR__.'/../Framework/OpenApiGenerator.php';

function api_security_scheme_json(): string {
	$scheme=\Dataphyre\Api\SecurityScheme::apiKey('shopKey', ' X-Shop-Key ', 'QUERY', [
		'scopes'=>['read:orders', 'read:orders', ''],
		'resolver'=>['\\App\\Security\\ApiKeys', ' resolve '],
		'failure_status'=>403,
		'failure_message'=>'Forbidden',
		'description'=>' Shop API key ',
	]);
	return json_encode($scheme->toArray(), JSON_UNESCAPED_SLASHES);
}

function api_endpoint_compile_json(): string {
	$endpoint=\Dataphyre\Api\Endpoint::methods([' get ', 'GET', 'post'], 'v1/orders/{orderId}/', ['OrderController', 'show'])
		->tag('orders', ['checkout', ''])
		->aliases(['/orders.show', 'orders.detail', 'orders.show'])
		->summary(' Order detail ')
		->queryParameter('include', ['type'=>'string'], ['required'=>false, 'description'=>' Extra data '])
		->jsonResponse(200, ['type'=>'object'], 'Fetched')
		->auth(\Dataphyre\Api\SecurityScheme::bearer('bearerAuth', ['scopes'=>['orders:read', 'orders:read']]))
		->server(' https://api.example.test ', ' Primary ')
		->cache(0, ['names'=>['orders', 'orders', '']])
		->profile(' public ', ['tier'=>'gold']);

	$compiled=$endpoint->compile();
	return json_encode($compiled['api'], JSON_UNESCAPED_SLASHES);
}

function api_group_compile_json(): string {
	$group=\Dataphyre\Api\ApiGroup::make(' partner ')
		->prefix('/v2/shops/')
		->tag('shops', ['partners', 'shops'])
		->authAll(
			\Dataphyre\Api\SecurityScheme::basic('basicAuth', ['scopes'=>'shops:read']),
			\Dataphyre\Api\SecurityScheme::apiKey('partnerKey', ' X-Partner-Key ', 'header')
		)
		->server(' https://partner.example.test/ ', ' Partner ')
		->withTrace(false, ['sample'=>'unit'])
		->dispatchDefaults(['source'=>'group']);

	$compiled=$group->get('/{shopId}/', 'ShopController::show')
		->operationId(' partnerShopShow ')
		->jsonBody(['type'=>'object'], true, ' Filters ')
		->jsonResponse(202, ['type'=>'object'], 'Accepted')
		->cache('25', ['names'=>['shops', 'partner', 'shops'], 'vary'=>['language']])
		->compile();

	return json_encode($compiled['api'], JSON_UNESCAPED_SLASHES);
}

function api_openapi_generator_json(): string {
	$endpoint=\Dataphyre\Api\Endpoint::post('/v1/carts/{cartId}', 'CartController::update')
		->tag('carts')
		->summary(' Update cart ')
		->description(' Updates a cart from API payload. ')
		->operationId(' updateCart ')
		->deprecated()
		->jsonBody(['type'=>'object', 'required'=>['sku']], true)
		->jsonResponse(201, ['type'=>'object'], 'Created')
		->auth(\Dataphyre\Api\SecurityScheme::oauth2('oauth', [
			'clientCredentials'=>[
				'tokenUrl'=>'https://auth.example.test/token',
				'scopes'=>['cart:write'=>'Write carts'],
			],
		], ['scopes'=>['cart:write']]))
		->server('https://edge.example.test');

	$generator=new \Dataphyre\Api\OpenApiGenerator();
	return json_encode($generator->generate([$endpoint->compile()['api']], [
		'title'=>' Storefront API ',
		'version'=>' 2026.05 ',
		'description'=>' Public cart API ',
		'servers'=>[['url'=>' https://api.example.test/root '], '', ['description'=>'missing-url']],
	]), JSON_UNESCAPED_SLASHES);
}

function api_context_sources_and_auth_json(): string {
	$request=\Dataphyre\Http\Request::create(
		'post',
		'v1/orders/42/',
		['include'=>'lines', 'nested'=>['query'=>'q']],
		['nested'=>['body'=>'b'], 'sku'=>'sku-1'],
		['cart'=>'cart-1'],
		['REMOTE_ADDR'=>'127.0.0.1'],
		['X-Shop-Key'=>'abc', 'Accept'=>'application/json'],
		['orderId'=>'42', 'nested'=>['route'=>'r']],
		[
			'dataphyre_api_auth'=>[
				'authorized'=>true,
				'scheme'=>' bearer ',
				'identity'=>['user_id'=>7],
				'scopes'=>['orders:read'],
				'context'=>['tenant'=>['id'=>'shop-1']],
				'meta'=>['token_id'=>'tok-1'],
			],
		]
	);
	$context=(new \Dataphyre\Api\ApiContext($request, ['api'=>['dispatch'=>['source'=>'route']]]))
		->withBindings(['product'=>['sku'=>'sku-1']], [['name'=>'product']]);

	return json_encode([
		'method'=>$context->method(),
		'path'=>$context->path(),
		'parameter'=>$context->parameters('orderId'),
		'all'=>$context->all(['query', 'body', 'route']),
		'input_sku'=>$context->input('sku'),
		'cookie'=>$context->cookie('cart'),
		'header'=>$context->header('x-shop-key'),
		'has_auth'=>$context->hasAuth(),
		'auth_scheme'=>$context->authScheme(),
		'auth_identity'=>$context->authIdentity(),
		'auth_context'=>$context->authContext('tenant.id'),
		'auth_meta'=>$context->authMeta('token_id'),
		'binding'=>$context->binding('product.sku'),
		'binding_trace'=>$context->bindingTrace(),
		'binding_data'=>$context->bindingData(),
	], JSON_UNESCAPED_SLASHES);
}

function api_callable_binding_json(): string {
	$request=\Dataphyre\Http\Request::create('get', '/v1/ping', ['q'=>'search'], [], [], [], [], ['id'=>'123']);
	$api_context=new \Dataphyre\Api\ApiContext($request, ['api'=>['name'=>'ping']]);
	$binding_context=new \Dataphyre\Templating\BindingContext(
		'unit-template',
		true,
		['tenant'=>'shop-1'],
		[],
		[],
		['api_context'=>$api_context]
	);
	$binding=new \Dataphyre\Api\ApiCallableBinding(
		static fn(\Dataphyre\Api\ApiContext $context, \Dataphyre\Http\Request $request): array => [
			'method'=>$context->method(),
			'path'=>$request->path(),
			'route_id'=>$context->parameters('id'),
			'query'=>$context->query('q'),
		],
		'api.unit',
		'orders.show',
		static fn(\Dataphyre\Templating\BindingContext $context): string => ' tenant:'.$context->get('tenant').' '
	);
	return json_encode([
		'name'=>$binding->name(),
		'metadata'=>$binding->metadata(),
		'identity'=>$binding->cacheIdentity($binding_context),
		'resolved'=>$binding->resolve($binding_context),
	], JSON_UNESCAPED_SLASHES);
}
