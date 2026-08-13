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
use Dataphyre\Http\Request;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('API manager production-mode contracts')
	->framework(['api','http','routing','sanitation','templating'], [
		'constants'=>['Dataphyre\\Api\\IS_PRODUCTION'=>true],
	])
	->tag('api','manager','coverage')
	->group('framework-coverage')
	->sandboxesRootpath('api_cache')
	->maxMillis(15000);

final class DpApiProductionTarget {
	public static function handle(ApiContext $context): array {
		$calls=\Dataphyre\Test\TestState::channel('api.manager.production')->increment('target_calls');
		return ['ok'=>true,'calls'=>$calls];
	}
}

final class DpApiProductionBinding implements \Dataphyre\Templating\DataBinding {
	public int $calls=0;
	public function name(): string { return 'production.binding'; }
	public function resolve(\Dataphyre\Templating\BindingContext $context): mixed {
		$this->calls++;
		return ['production'=>true];
	}
}

test('api manager production mode disables traces while execution caching and bindings remain functional',static function(Context $t): void {
	$t->state('api.manager.production',['target_calls'=>0]);
	$apiManagerInternals=$t->nonPublic(ApiManager::instance());
	$t->isFalse($apiManagerInternals->invoke('tracingEnabled'));
	$options=$apiManagerInternals->invoke('normalizeTraceOptions',[
		'enabled'=>true,'response_key'=>' edge_trace ','header'=>' X-Edge-Trace ',
	]);
	$t->hasPathValues([
		'enabled'=>false,
		'response_key'=>'edge_trace',
		'header'=>'X-Edge-Trace',
	],$options);
	$t->same('direct',$apiManagerInternals->invoke('executeWithTraceContext',
		['api_trace_id'=>'trace'],static fn(): string=>'direct',$options,
	));
	$t->same([],$apiManagerInternals->invoke('recentSqlTracePayload',
		['api_trace_id'=>'trace'],10,
	));

	$request=Request::create('GET','/production');
	$route=['path_template'=>'/production','api'=>['path'=>'/production']];
	$context=new ApiContext($request,$route);
	$bindingContext=$apiManagerInternals->invoke('bindingContextForApi',
		$context,['prior'=>true],'value',['api_trace_id'=>'ignored'],1,
	);
	$t->same([],$bindingContext->traceContext());
	$binding=new DpApiProductionBinding();
	$t->same(['production'=>true],$apiManagerInternals->invoke('resolveApiBindingWithTraceContext',
		$binding,$bindingContext,['driver'=>'sql'],['api_trace_id'=>'ignored'],'value',
	));
	$t->same(1,$binding->calls);

	$manager=ApiManager::instance();
	$plain=Endpoint::get('/production')
		->withTrace(true)
		->execute(DpApiProductionTarget::class.'::handle')
		->compile();
	$response=$manager->executeCompiledRoute($plain,$request);
	$t->same(200,$response?->status);
	$t->isFalse(isset($response?->headers['X-Dataphyre-Api-Trace']));

	$name='api-production-'.bin2hex(random_bytes(4));
	$t->defer(static fn()=>$manager->clearEndpointCache($name));
	$manager->clearEndpointCache($name);
	$cached=Endpoint::get('/production-cache')
		->withTrace(true)
		->cache(60,['names'=>[$name]])
		->execute(DpApiProductionTarget::class.'::handle')
		->compile();
	$cacheRequest=Request::create('GET','/production-cache');
	$first=$manager->executeCompiledRoute($cached,$cacheRequest);
	$second=$manager->executeCompiledRoute($cached,$cacheRequest);
	$t->same($first?->body,$second?->body);
	$manager->clearEndpointCache($name);
});
