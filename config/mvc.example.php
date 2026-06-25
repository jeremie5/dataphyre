<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

return [
	'default_app'=>'default',
	'controllers'=>[
		'namespace'=>'App\\Controllers',
	],
	'models'=>[
		'namespace'=>'App\\Models',
	],
	'views'=>[
		'path'=>defined('ROOTPATH') && isset(ROOTPATH['app'])
			? ROOTPATH['app'].'views'
			: __DIR__.'/../views',
	],
	'middleware'=>[
		// 'cache' is registered by default as Dataphyre\Mvc\CacheMiddleware::class.
		// 'session' is registered by default as Dataphyre\Mvc\SessionMiddleware::class.
		// 'csrf' is registered by default as Dataphyre\Mvc\CsrfMiddleware::class.
		// 'signed' is registered by default as Dataphyre\Mvc\SignedUrlMiddleware::class.
		// 'throttle' is registered by default as Dataphyre\Mvc\ThrottleMiddleware::class.
		// 'auth'=>App\Http\Middleware\AuthMiddleware::class,
		// 'tenant'=>['class'=>App\Http\Middleware\TenantMiddleware::class, 'parameters'=>['strict']],
	],
	'global_middleware'=>[
		// 'tenant',
	],
	'middleware_groups'=>[
		// 'web'=>['session', 'csrf'],
		// 'api'=>['throttle:api'],
	],
	'providers'=>[
		// App\Providers\AppServiceProvider::class,
	],
	'model_bindings'=>[
		// 'product'=>['model'=>App\Models\Product::class, 'key'=>'slug'],
	],
	'signed_url_secret'=>getenv('DATAPHYRE_MVC_SIGNING_KEY') ?: null,
	// Routes can be a closure, a route file path, a directory of route files,
	// or a mixed list of closures/files/array definitions.
	'manifest_cache'=>false,
	'routes'=>function(\Dataphyre\Mvc\RouteCollection $routes): void {
		$routes->get('/', 'HomeController@index')->name('home');
		$routes->get('/health', static fn(): array => ['ok'=>true]);
	},
	'response_headers'=>[
		'X-Dataphyre-MVC'=>'1',
	],
	'not_found_handler'=>null,
	'error_handler'=>null,
	'validation_redirect'=>false,
	'validation_redirect_fallback'=>'/',
	// You can also register apps at runtime with:
	// \Dataphyre\Mvc\Mvc::register('default', [...]);
];
