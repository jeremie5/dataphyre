<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Mvc\Controller;
use Dataphyre\Mvc\FormRequest;
use Dataphyre\Mvc\HttpException;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\MvcRouteContext;
use Dataphyre\Mvc\ValidationException;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'routing', 'mvc']);

final class DpMvcCoverageMiddleware {
	public static array $events=[];
	public function handle(Request $request, callable $next, string $label='default'): mixed {
		self::$events[]='before:'.$label;
		$response=$next($request);
		self::$events[]='after:'.$label;
		return $response;
	}
	public function terminate(Request $request, Response $response, string $label='default'): void {
		self::$events[]='terminate:'.$label.':'.$response->status;
	}
}

final class DpMvcCallableMiddleware {
	public static int $calls=0;
	public function __invoke(Request $request, callable $next, string $label='callable'): mixed {
		self::$calls++;
		return $next($request)->withHeader('X-Callable-Middleware', $label);
	}
}

final class DpMvcCoverageFormRequest extends FormRequest {
	public bool $passed=false;
	public function rules(): array { return ['name'=>'required|string']; }
	protected function prepareForValidation(): void { $this->merge(['name'=>trim((string)$this->input('name'))]); }
	protected function passedValidation(): void { $this->passed=true; }
}

final class DpMvcCoverageController extends Controller {
	public function __construct(){
		$this->middleware(['class'=>DpMvcCoverageMiddleware::class, 'parameters'=>['controller']])->only('show');
		$this->middleware(new DpMvcCallableMiddleware())->except('plain');
	}
	public function show(Request $request, MvcRouteContext $context, string $id): array {
		return [
			'id'=>$id,
			'name'=>$context->name(),
			'attribute'=>$request->attribute('route_name'),
			'parameter'=>$context->parameter('id'),
		];
	}
	public function plain(): string { return 'plain-controller'; }
	public static function staticAction(): string { return 'static-controller'; }
	public function fail(): never { throw new HttpException(418, 'teapot', ['X-Tea'=>'yes']); }
}

final class DpMvcInvokableHandler {
	public function __invoke(Request $request): string { return 'invoked:'.$request->method(); }
}

test('mvc dispatcher invokes closures controllers arrays static handlers form requests and middleware', static function(Context $t): void {
	DpMvcCoverageMiddleware::$events=[];
	DpMvcCallableMiddleware::$calls=0;
	$app=new MvcApplication('dispatch', [
		'response_headers'=>['X-Framework'=>'dataphyre'],
		'middleware'=>[
			'trace'=>DpMvcCoverageMiddleware::class,
			'callable'=>DpMvcCallableMiddleware::class,
		],
		'middleware_groups'=>[
			'web'=>['trace:group', ['alias'=>'callable', 'parameters'=>['group-callable']]],
			'api'=>[['group'=>'web']],
		],
		'global_middleware'=>static fn(Request $request, callable $next)=>$next($request)->withHeader('X-Global', 'yes'),
		'middleware_stack'=>[['alias'=>'trace', 'parameters'=>['stack']]],
	]);
	$routes=$app->routes();
	$routes->get('/closure/{id}', static function(Request $request, MvcRouteContext $context, string $id): array {
		return ['id'=>$id, 'route'=>$context->name(), 'method'=>$request->method()];
	}, ['name'=>'closure', 'middleware'=>['api']]);
	$routes->get('/excluded', static fn(): string=>'excluded', [
		'middleware'=>['trace:excluded'],
		'without_middleware'=>['trace:excluded'],
	]);
	$routes->get('/controller/{id}', DpMvcCoverageController::class.'@show', ['name'=>'controller.show']);
	$routes->get('/controller-array', [DpMvcCoverageController::class, 'plain']);
	$routes->get('/controller-descriptor', [
		'type'=>'controller', 'class'=>DpMvcCoverageController::class, 'method'=>'plain',
	]);
	$routes->get('/static', [
		'class'=>DpMvcCoverageController::class, 'method'=>'staticAction', 'static'=>true,
	]);
	$routes->post('/form', static fn(DpMvcCoverageFormRequest $form): array=>[
		'name'=>$form->validated('name'), 'passed'=>$form->passed,
	]);
	$routes->get('/invokable', new DpMvcInvokableHandler());

	$response=$app->dispatcher()->dispatch(Request::create('GET', '/closure/42'));
	$t->same(200, $response->status);
	$t->same(['id'=>'42', 'route'=>'closure', 'method'=>'GET'], json_decode($response->body, true));
	$t->same('dataphyre', $response->headers['X-Framework']);
	$t->same('yes', $response->headers['X-Global']);
	$t->same('group-callable', $response->headers['X-Callable-Middleware']);
	$t->contains('before:group', DpMvcCoverageMiddleware::$events);
	$t->contains('before:stack', DpMvcCoverageMiddleware::$events);
	$t->contains('terminate:group:200', DpMvcCoverageMiddleware::$events);

	$controller=$app->dispatcher()->dispatch(Request::create('GET', '/controller/7'));
	$t->same(['id'=>'7', 'name'=>'controller.show', 'attribute'=>'controller.show', 'parameter'=>'7'], json_decode($controller->body, true));
	$t->contains('before:controller', DpMvcCoverageMiddleware::$events);
	$t->isTrue(DpMvcCallableMiddleware::$calls>0);
	$t->same('plain-controller', $app->dispatcher()->dispatch(Request::create('GET', '/controller-array'))->body);
	$t->same('plain-controller', $app->dispatcher()->dispatch(Request::create('GET', '/controller-descriptor'))->body);
	$t->same('static-controller', $app->dispatcher()->dispatch(Request::create('GET', '/static'))->body);
	$t->same(['name'=>'Ada', 'passed'=>true], json_decode($app->dispatcher()->dispatch(
		Request::create('POST', '/form', [], ['name'=>' Ada '])
	)->body, true));
	$t->same('invoked:GET', $app->dispatcher()->dispatch(Request::create('GET', '/invokable'))->body);
	$t->same('excluded', $app->dispatcher()->dispatch(Request::create('GET', '/excluded'))->body);
})->tag('mvc', 'dispatcher', 'coverage')->group('framework-coverage');

test('mvc dispatcher normalizes response variants handles misses and refreshes its manifest revision', static function(Context $t): void {
	$app=new MvcApplication('responses', [
		'response_headers'=>['X-Default'=>'one'],
		'not_found_handler'=>static fn(Request $request): Response=>Response::json(['missing'=>$request->path()], 404),
	]);
	$routes=$app->routes();
	$routes->get('/string', static fn()=>'hello');
	$routes->get('/array', static fn()=>['ok'=>true]);
	$routes->get('/null', static fn()=>null);
	$routes->get('/response', static fn()=>Response::make('made', 201));
	$routes->redirect('/redirect', '/string', 307);
	$dispatcher=$app->dispatcher();
	$t->same('hello', $dispatcher->dispatch(Request::create('GET', '/string'))->body);
	$t->same(['ok'=>true], json_decode($dispatcher->dispatch(Request::create('GET', '/array'))->body, true));
	$t->same('', $dispatcher->dispatch(Request::create('GET', '/null'))->body);
	$t->same(201, $dispatcher->dispatch(Request::create('GET', '/response'))->status);
	$redirect=$dispatcher->dispatch(Request::create('GET', '/redirect'));
	$t->same(307, $redirect->status);
	$t->same('/string', $redirect->headers['Location']);
	$missing=$dispatcher->dispatch(Request::create('GET', '/missing'));
	$t->same(404, $missing->status);
	$t->same(['missing'=>'/missing'], json_decode($missing->body, true));
	$routes->get('/late', static fn()=>'late');
	$t->same('late', $dispatcher->dispatch(Request::create('GET', '/late'))->body);

	$default404=(new MvcApplication('default-404'))->dispatcher()->dispatch(Request::create('GET', '/none'));
	$t->same(404, $default404->status);
	$t->same('Not Found', $default404->body);
})->tag('mvc', 'dispatcher', 'coverage')->group('framework-coverage');

test('mvc dispatcher converts validation HTTP model and configured errors for html and json clients', static function(Context $t): void {
	$app=new MvcApplication('errors', [
		'response_headers'=>['X-Error-Default'=>'yes'],
		'validation_redirect'=>true,
		'validation_redirect_fallback'=>'/form',
	]);
	$routes=$app->routes();
	$routes->get('/http', static fn()=>throw new HttpException(403, 'denied'));
	$routes->get('/validation', static fn()=>throw ValidationException::withMessages(['name'=>['Name required.']]));
	$routes->get('/runtime', static fn()=>throw new RuntimeException('unhandled'));
	$html=$app->dispatcher()->dispatch(Request::create('GET', '/http'));
	$t->same(403, $html->status);
	$t->same('denied', $html->body);
	$json=$app->dispatcher()->dispatch(Request::create('GET', '/http', [], [], [], [], ['Accept'=>'application/json']));
	$t->same(403, $json->status);
	$t->same(['message'=>'denied', 'status'=>403], json_decode($json->body, true));
	$validationJson=$app->dispatcher()->dispatch(Request::create('GET', '/validation', [], [], [], [], ['Accept'=>'application/json']));
	$t->same(422, $validationJson->status);
	$t->contains('Name required.', $validationJson->body);
	$validationRedirect=$app->dispatcher()->dispatch(Request::create(
		'GET', '/validation', [], ['name'=>''], [], ['HTTP_REFERER'=>'/previous']
	));
	$t->same(302, $validationRedirect->status);
	$t->same('/previous', $validationRedirect->headers['Location']);
	$t->throws(static fn()=>$app->dispatcher()->dispatch(Request::create('GET', '/runtime')), RuntimeException::class);

	$handled=new MvcApplication('handled', [
		'error_handler'=>static fn(Throwable $error, Request $request): array=>[
			'error'=>$error->getMessage(), 'path'=>$request->path(),
		],
	]);
	$handled->routes()->get('/boom', static fn()=>throw new LogicException('boom'));
	$t->same(
		['error'=>'boom', 'path'=>'/boom'],
		json_decode($handled->dispatcher()->dispatch(Request::create('GET', '/boom'))->body, true)
	);
})->tag('mvc', 'dispatcher', 'coverage')->group('framework-coverage');

test('mvc dispatcher reads writes and reuses a signed manifest cache', static function(Context $t): void {
	$manifest=$t->workspace('mvc-dispatcher-manifest')->path('manifest.php');
	$app=new MvcApplication('cached-dispatch', ['manifest_cache'=>$manifest]);
	$app->routes()->get('/cached', [
		'class'=>DpMvcCoverageController::class, 'method'=>'staticAction', 'static'=>true,
	]);
	$t->same('static-controller', $app->dispatcher()->dispatch(Request::create('GET', '/cached'))->body);
	$t->isTrue(is_file($manifest));
	$second=new MvcApplication('cached-dispatch', ['manifest_cache'=>$manifest]);
	$second->routes()->get('/cached', [
		'class'=>DpMvcCoverageController::class, 'method'=>'staticAction', 'static'=>true,
	]);
	$t->same('static-controller', $second->dispatcher()->dispatch(Request::create('GET', '/cached'))->body);
})->tag('mvc', 'dispatcher', 'coverage')->group('framework-coverage');

test('mvc dispatcher compiles in memory without source-local writes in managed contexts', static function(Context $t): void {
	$manifest=$t->workspace('mvc-managed-manifest')->path('manifest.php');
	$probe=$t->phpProcess([
		__DIR__.'/fixtures/mvc_manifest_cache_write_boundary.php',
		dirname(__DIR__, 2),
		$manifest,
	]);
	$t->processSucceeded($probe);
	$t->same('', $probe->stderr());
	$t->same([
		'status'=>200,
		'body'=>'compiled-in-memory',
		'manifest_exists'=>false,
	], $probe->json());
})->tag('mvc', 'dispatcher', 'managed-runtime', 'cache')->group('framework-coverage');
