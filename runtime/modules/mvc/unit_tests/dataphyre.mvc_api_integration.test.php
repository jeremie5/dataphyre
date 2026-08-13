<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Api\ApiContext;
use Dataphyre\Api\ApiManager;
use Dataphyre\Api\Endpoint;
use Dataphyre\Api\SecurityScheme;
use Dataphyre\Http\Request;
use Dataphyre\Mvc\Mvc;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\RouteDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!class_exists('dataphyre\\core',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class core {
	public static function dialback(string $event,mixed ...$arguments): mixed { return null; }
	public static function load_framework_module(string $module): void {}
}
PHP);
}

suite('MVC and API share one compiled route contract')
	->framework(['api','http','mvc','routing','sanitation','templating'])
	->tag('mvc','api','integration')
	->group('framework-coverage')
	->maxMillis(15000);

final class DpMvcApiIntegrationTarget {
	public static function execute(ApiContext $context): array {
		return ['source'=>'api','path'=>$context->path()];
	}

	public static function cached(): string {
		return 'cache-source-ready';
	}

	public static function authorize(mixed $credentials): array {
		return $credentials==='secret'
			? ['authorized'=>true,'identity'=>['id'=>7]]
			: ['authorized'=>false,'status'=>403,'message'=>'MVC API credential denied.'];
	}
}

test('route API metadata remains named, aliased, inspectable, and compiler-visible',static function(Context $t): void {
	$route=RouteDefinition::make('GET','/metadata',static fn(): string=>'metadata')
		->api(['path'=>'/metadata','operation_id'=>'','aliases'=>[]])
		->name('mvc.api.metadata');
	$t->same([
		'path'=>'/metadata',
		'operation_id'=>'mvc.api.metadata',
		'aliases'=>['mvc.api.metadata'],
	],$route->apiMetadata());
	$t->same($route->apiMetadata(),$route->compile()['api']);

	$namedFirst=RouteDefinition::make('GET','/named-first',static fn(): string=>'named')
		->name('mvc.api.named_first')
		->api(['path'=>'/named-first']);
	$t->same('mvc.api.named_first',$namedFirst->apiMetadata()['operation_id']);
	$t->contains('mvc.api.named_first',$namedFirst->apiMetadata()['aliases']);
});

test('MVC dispatch authorizes and executes API metadata without bypassing ordinary route fallback',static function(Context $t): void {
	$app=new MvcApplication('mvc-api-dispatch');
	$endpoint=Endpoint::get('/mvc-api')
		->execute(DpMvcApiIntegrationTarget::class.'::execute')
		->compile();
	$app->routes()->get('/mvc-api',static fn(): array=>['source'=>'mvc-fallback'])
		->api($endpoint['api'])
		->name('mvc.api.execute');
	$app->routes()->get('/mvc-controller',static fn(): array=>['source'=>'mvc'])
		->api(['path'=>'/mvc-controller','operation_id'=>'mvc.controller'])
		->name('mvc.api.controller');

	$executed=$app->dispatcher()->dispatch(Request::create('GET','/mvc-api'));
	$t->same(200,$executed->status);
	$t->hasPathValues(['source'=>'api','path'=>'/mvc-api'],$t->decodeJson($executed->body));
	$fallback=$app->dispatcher()->dispatch(Request::create('GET','/mvc-controller'));
	$t->hasPathValues(['source'=>'mvc'],$t->decodeJson($fallback->body));

	$scheme=SecurityScheme::apiKey('mvcKey','X-Mvc-Key','header',[
		'resolver'=>[DpMvcApiIntegrationTarget::class,'authorize'],
	]);
	$app->routes()->get('/mvc-secure',static fn(): array=>['source'=>'forbidden-fallback'])
		->api([
			'path'=>'/mvc-secure',
			'operation_id'=>'mvc.secure',
			'security'=>[['mvcKey'=>[]]],
			'security_schemes'=>['mvcKey'=>$scheme->toArray()],
		]);
	$denied=$app->dispatcher()->dispatch(Request::create('GET','/mvc-secure',[],[],[],[],['X-Mvc-Key'=>'wrong']));
	$t->same(403,$denied->status);
	$t->contains('MVC API credential denied.',$denied->body);
});

test('declared API security and execution fail closed when the API framework is unavailable',static function(Context $t): void {
	$probe=$t->phpProcess([
		__DIR__.'/fixtures/mvc_api_bridge_unavailable_probe.php',
		dirname(__DIR__,2),
	]);
	$t->processSucceeded($probe);
	$t->same('',$probe->stderr());
	$payload=$probe->json();
	foreach(['secure','execute'] as $surface){
		$t->same(503,$payload[$surface]['status'] ?? null);
		$t->same('no-store',$payload[$surface]['cache_control'] ?? null);
		$t->same(false,$payload[$surface]['body']['ok'] ?? null);
		$t->same('API framework is unavailable.',$payload[$surface]['body']['error'] ?? null);
	}
	$t->same(200,$payload['metadata']['status'] ?? null);
	$t->same('mvc',$payload['metadata']['body']['source'] ?? null);
});

test('declared route dependencies invalidate manifest signatures without sleeps or raw timestamp plumbing',static function(Context $t): void {
	$workspace=$t->workspace('mvc-manifest-sources');
	$dependency=$workspace->file('route-security.php',"<?php\n// revision one\n");
	$cache=$workspace->path('manifest.php');
	$config=['manifest_cache'=>['file'=>$cache,'sources'=>$dependency]];
	$app=new MvcApplication('mvc-cache-sources',$config);
	$app->routes()->get('/cache-source',[DpMvcApiIntegrationTarget::class,'cached'])->name('cache.source');
	$dispatcher=$t->nonPublic($app->dispatcher());
	$sources=$dispatcher->invoke('manifestSources');
	$t->hasKey($dependency,$sources);
	$firstSignature=$dispatcher->invoke('manifestSignature',$app->routes()->revision());
	$t->same('cache-source-ready',$app->dispatcher()->dispatch(Request::create('GET','/cache-source'))->body);

	$workspace->advanceMtime('route-security.php',5);
	$refreshed=new MvcApplication('mvc-cache-sources',$config);
	$refreshed->routes()->get('/cache-source',[DpMvcApiIntegrationTarget::class,'cached'])->name('cache.source');
	$secondSignature=$t->nonPublic($refreshed->dispatcher())->invoke('manifestSignature',$refreshed->routes()->revision());
	$t->notSame($firstSignature,$secondSignature);
	$t->same('cache-source-ready',$refreshed->dispatcher()->dispatch(Request::create('GET','/cache-source'))->body);
	$t->throws(static fn()=>(new MvcApplication('invalid-cache-sources',[
		'manifest_cache'=>['file'=>$cache,'sources'=>new stdClass()],
	]))->manifestCacheSources(),RuntimeException::class);
});

test('API application discovery falls back to the registered MVC manifest',static function(Context $t): void {
	Mvc::flush();
	$app=Mvc::register('mvc-api-manifest',new MvcApplication('mvc-api-manifest'));
	$app->routes()->get('/manifest-route',static fn(): string=>'manifest')
		->api(['path'=>'/manifest-route','operation_id'=>'mvc.manifest'])
		->name('mvc.manifest');
	$workspace=$t->workspace('mvc-api-manifest');
	$definition=new \dataphyre\application_definition('mvc-api-manifest',$workspace->root());
	$t->nonPublic(\dataphyre\runtime::class)
		->replacePropertyForTest('current_application_definition',$definition)
		->replacePropertyForTest('current_project_root',$workspace->root());

	$manifest=$t->nonPublic(ApiManager::instance())->invoke('applicationManifest');
	$t->notEmpty($manifest['routes']);
	$t->same('mvc.manifest',$manifest['routes'][0]['name']);
	$t->same('mvc.manifest',$manifest['routes'][0]['api']['operation_id']);

	Mvc::flush();
	$broken=Mvc::register('mvc-api-manifest',new MvcApplication('mvc-api-manifest'));
	$broken->routes()->get('/broken-manifest',new stdClass());
	$fallback=$t->nonPublic(ApiManager::instance())->invoke('applicationManifest');
	$t->same([], $fallback['routes']);
	$t->same('mvc-api-manifest', $fallback['metadata']['application']);
});
