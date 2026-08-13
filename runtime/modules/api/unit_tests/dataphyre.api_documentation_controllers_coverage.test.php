<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class ApiControllerLoadProbe {
		private static bool $shouldFail=false;
		public static function fail(): void { self::$shouldFail=true; }
		public static function allow(): void { self::$shouldFail=false; }
		public static function shouldFail(): bool { return self::$shouldFail; }
	}

	if(!class_exists(core::class, false)){
		final class core {
			public static function load_framework_module(string $module): string {
				if(ApiControllerLoadProbe::shouldFail()){
					throw new \RuntimeException('API framework load failure.');
				}
				return $module;
			}
			public static function dialback(string $event, mixed ...$arguments): mixed {
				return null;
			}
		}
	}
}

namespace {
	use Dataphyre\Api\OpenApiController;
	use Dataphyre\Api\SwaggerUiController;
	use Dataphyre\Http\Request;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['http', 'routing', 'api']);

	test('api OpenAPI controller covers successful fallback encoding and generation failures', static function(Context $t): void {
		$request=Request::create('GET', '/_framework/api/openapi.json');
		$response=OpenApiController::show($request, [
			'api_docs'=>[
				'title'=>'Coverage API',
				'version'=>'2.0.0',
				'servers'=>[['url'=>'https://api.example.test']],
			],
		]);
		$t->same(200, $response->status);
		$t->same('application/vnd.oai.openapi+json; charset=utf-8', $response->headers['Content-Type']);
		$document=json_decode($response->body, true);
		$t->same('3.1.0', $document['openapi']);
		$t->same('Coverage API', $document['info']['title']);

		$invalid=OpenApiController::show($request, ['api_docs'=>['title'=>"\xB1\x31"]]);
		$t->same('{}', $invalid->body);
		\dataphyre\ApiControllerLoadProbe::fail();
		$t->defer(static fn()=>\dataphyre\ApiControllerLoadProbe::allow());
		$failure=OpenApiController::show($request, ['api_docs'=>'invalid']);
		$t->same(500, $failure->status);
		$t->contains('API framework load failure.', $failure->body);
		\dataphyre\ApiControllerLoadProbe::allow();
	})->tag('api', 'controllers', 'coverage')->group('framework-coverage');

	test('api Swagger UI controller covers shell assets conditionals HEAD and misses', static function(Context $t): void {
		$show=SwaggerUiController::show(Request::create('GET', '/docs'), [
			'api_docs'=>[
				'title'=>'Coverage <API>',
				'spec_path'=>'/spec.json?x=1',
				'asset_path'=>'docs/assets/',
				'swagger_ui_css'=>'https://cdn.example.test/swagger.css?x=1&y=2',
				'swagger_ui_bundle_js'=>'https://cdn.example.test/bundle.js',
				'swagger_ui_preset_js'=>'https://cdn.example.test/preset.js',
			],
		]);
		$t->same(200, $show->status);
		$t->contains('Coverage &lt;API&gt;', $show->body);
		$t->contains('/docs/assets/swagger-shell.css?v=', $show->body);
		$t->contains('data-dataphyre-openapi-spec="/spec.json?x=1"', $show->body);
		$t->contains('swagger.css?x=1&amp;y=2', $show->body);

		$missing=SwaggerUiController::asset(Request::create('GET', '/assets/missing'), [
			'parameters'=>['asset'=>'../missing.txt'],
		]);
		$t->same(404, $missing->status);
		$t->same('no-store', $missing->headers['Cache-Control']);

		$cssRequest=Request::create('GET', '/assets/swagger-shell.css');
		$cssRequest->mergeRouteParameters(['asset'=>'swagger-shell.css']);
		$css=SwaggerUiController::asset($cssRequest, []);
		$t->same(200, $css->status);
		$t->same('text/css; charset=UTF-8', $css->headers['Content-Type']);
		$t->contains('.swagger-ui', $css->body);
		$etag=$css->headers['ETag'];

		$notModified=SwaggerUiController::asset(
			Request::create('GET', '/assets/swagger-shell.css', [], [], [], [], ['If-None-Match'=>$etag]),
			['parameters'=>['asset'=>'swagger-shell.css']]
		);
		$t->same(304, $notModified->status);
		$modifiedSince=SwaggerUiController::asset(
			Request::create('GET', '/assets/swagger-init.js', [], [], [], [], ['If-Modified-Since'=>'Thu, 01 Jan 2026 00:00:00 GMT']),
			['parameters'=>['asset'=>'swagger-init.js']]
		);
		$t->same(304, $modifiedSince->status);

		$head=SwaggerUiController::asset(Request::create('HEAD', '/assets/swagger-init.js'), [
			'parameters'=>['asset'=>'swagger-init.js'],
		]);
		$t->same(200, $head->status);
		$t->same('', $head->body);
		$t->isTrue((int)$head->headers['Content-Length']>0);
		$js=SwaggerUiController::asset(Request::create('GET', '/assets/swagger-init.js'), [
			'parameters'=>['asset'=>'swagger-init.js'],
		]);
		$t->same('application/javascript; charset=UTF-8', $js->headers['Content-Type']);
		$t->contains('SwaggerUIBundle', $js->body);
	})->tag('api', 'controllers', 'coverage')->group('framework-coverage');
}
