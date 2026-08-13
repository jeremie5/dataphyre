<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Http\Request;
use Dataphyre\Mvc\Controller;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\MvcDispatcher;
use Dataphyre\Mvc\RouteModelNotFoundException;
use Dataphyre\Templating\RenderedTemplate;
use Dataphyre\Templating\TemplatingManager;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'http'=>true, 'routing'=>true, 'templating'=>true, 'mvc'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_mvc_dispatcher_deep_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_mvc_dispatcher_deep_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_mvc_dispatcher_deep_modules_root);
\dataphyre\autoloader::register_framework_modules(['http', 'routing', 'templating', 'mvc']);
require_once $dp_mvc_dispatcher_deep_modules_root.'/templating/unit_tests/templating_render_test_helpers.php';

final class DpMvcDispatcherDeepShortController extends Controller {
	public function index(): string {
		return 'short-controller';
	}
}

if(!class_exists('DpMvcDispatcherDeepNamespace\\ShortController', false)){
	class_alias(DpMvcDispatcherDeepShortController::class, 'DpMvcDispatcherDeepNamespace\\ShortController');
}

final class DpMvcDispatcherDeepInvokableMiddleware {
	public function __invoke(Request $request, callable $next): mixed {
		return $next($request);
	}
}

final class DpMvcDispatcherDeepPlainObject {}

test('mvc dispatcher deep coverage handles bootstrap short invalid model and corrupt manifest routes', static function(Context $t): void {
	$bootstrap=$t->workspace('mvc-dispatcher-bootstrap')->file('bootstrap.php',<<<'PHP'
<?php
final class DpMvcDispatcherDeepBootstrapTarget {
	public static function run(): string { return 'bootstrapped-controller'; }
}
PHP);
		$app=new MvcApplication('dispatcher-deep-routes', [
			'controllers'=>['namespace'=>'DpMvcDispatcherDeepNamespace'],
			'response_headers'=>'invalid-headers',
		]);
		$routes=$app->routes();
		$routes->get('/short', ['ShortController', 'index']);
		$routes->get('/bootstrap', [
			'type'=>'controller',
			'class'=>'DpMvcDispatcherDeepBootstrapTarget',
			'method'=>'run',
			'static'=>true,
			'bootstrap'=>$bootstrap,
		]);
		$routes->get('/invalid-handler', ['unsupported'=>'shape']);
		$routes->get('/missing-model', static fn()=>throw new RouteModelNotFoundException('MissingModel', 'model', 9));
		$dispatcher=$app->dispatcher();
		$t->same('short-controller', $dispatcher->dispatch(Request::create('GET', '/short'))->body);
		$t->same('bootstrapped-controller', $dispatcher->dispatch(Request::create('GET', '/bootstrap'))->body);
		$t->throws(static fn()=>$dispatcher->dispatch(Request::create('GET', '/invalid-handler')), RuntimeException::class);
		$t->same(404, $dispatcher->dispatch(Request::create('GET', '/missing-model'))->status);

		$brokenApp=new MvcApplication('dispatcher-broken-manifest');
		$brokenApp->routes()->get('/broken', static fn(): string=>'never');
		$broken=$brokenApp->dispatcher();
		$brokenInternals=$t->nonPublic($broken);
		$manifest=$brokenInternals->invoke('compiledManifest');
		$manifest['routes'][0]['metadata']['mvc']['route_index']=99;
		$brokenInternals->writeProperty('compiledManifest',$manifest);
		$t->throws(static fn()=>$broken->dispatch(Request::create('GET', '/broken')), RuntimeException::class);
})->tag('mvc', 'dispatcher', 'deep-coverage')->group('framework-coverage');

test('mvc dispatcher deep coverage exhausts middleware identity groups and resolution helpers', static function(Context $t): void {
	$app=new MvcApplication('dispatcher-deep-middleware', [
		'global_middleware'=>42,
		'middleware_groups'=>[
			'web'=>['alpha'],
		],
	]);
	$dispatcher=$app->dispatcher();
	$dispatcherInternals=$t->nonPublic($dispatcher);
	$t->same(['keep'],$dispatcherInternals->invoke('filterMiddleware',['keep','drop'],['drop']));
	$t->same(['string:'],$dispatcherInternals->invoke('middlewareKeys',''));
	$t->same(['string:'],$dispatcherInternals->invoke('middlewareKeys',''));
	$t->same(['class:DpFixture'],$dispatcherInternals->invoke('middlewareKeys',['class'=>'\\DpFixture']));

	$callable=static fn(Request $request, callable $next): mixed=>$next($request);
	$t->same(['callable'],$dispatcherInternals->invoke('middlewareKeys',['target'=>$callable]));
	$t->same(['callable'],$dispatcherInternals->invoke('middlewareKeys',$callable));
	$t->same(['callable'],$dispatcherInternals->invoke('middlewareKeys',$callable));
	$t->same(['callable'],$dispatcherInternals->invoke('middlewareKeys',new DpMvcDispatcherDeepInvokableMiddleware()));
	$t->same([DpMvcDispatcherDeepPlainObject::class],$dispatcherInternals->invoke('middlewareKeys',new DpMvcDispatcherDeepPlainObject()));
	$t->same(['int'],$dispatcherInternals->invoke('middlewareKeys',7));

	$t->same([],$dispatcherInternals->invoke('configuredMiddleware','global_middleware'));
	$t->same(['alpha'],$dispatcherInternals->invoke('expandMiddleware',['web']));
	$invalidGroups=(new MvcApplication('dispatcher-invalid-groups', ['middleware_groups'=>'invalid']))->dispatcher();
	$t->same(null,$t->nonPublic($invalidGroups)->invoke('middlewareGroup','web'));

	$resolved=$dispatcherInternals->invoke('resolveMiddleware',[
		'target'=>$callable,
		'parameters'=>['one'],
	]);
	$t->isTrue($callable===$resolved['target']);
	$t->same(['one'], $resolved['parameters']);
	$t->throws(static fn()=>$dispatcherInternals->invoke('resolveMiddleware',42),RuntimeException::class);
})->tag('mvc', 'dispatcher', 'deep-coverage')->group('framework-coverage');

test('mvc dispatcher deep coverage normalizes rendered templates and template views', static function(Context $t): void {
	$cache=$t->workspace('mvc-dispatcher-template')->directory('cache');
	$t->cleanup(static function(): void { TemplatingManager::flush(); });
		\dataphyre\templating::init(false, $cache.DIRECTORY_SEPARATOR, false);
		TemplatingManager::flush();
		$dispatcher=(new MvcApplication('dispatcher-template-results', ['response_headers'=>'invalid']))->dispatcher();
		$dispatcherInternals=$t->nonPublic($dispatcher);
		$rendered=$dispatcherInternals->invoke('normalizeResponse',
			new RenderedTemplate('<main>rendered-result</main>', 'dispatcher-rendered.tpl'),
		);
		$t->same('<main>rendered-result</main>', $rendered->body);
		$view=TemplatingManager::instance()->source('<article>template-view-result</article>', 'dispatcher-view.tpl');
		$viewResponse=$dispatcherInternals->invoke('normalizeResponse',$view);
		$t->contains('template-view-result', $viewResponse->body);
})->tag('mvc', 'dispatcher', 'deep-coverage')->group('framework-coverage');
