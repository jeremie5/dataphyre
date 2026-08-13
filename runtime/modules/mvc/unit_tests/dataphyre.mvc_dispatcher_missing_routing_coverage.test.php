<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Http\Request;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'http'=>true, 'mvc'=>true],
		'disabled'=>['routing'=>true],
		'core_implicit'=>true,
	]);
}
$dp_mvc_dispatcher_missing_routing_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_mvc_dispatcher_missing_routing_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_mvc_dispatcher_missing_routing_modules_root);
\dataphyre\autoloader::register_framework_modules(['http', 'mvc']);

test('mvc dispatcher reports the missing compiled routing dependency', static function(Context $t): void {
	$t->isFalse(class_exists('dataphyre\\routing\\compiled_route_dispatcher'));
	$app=new MvcApplication('dispatcher-without-routing');
	$dispatcher=$t->nonPublic($app->dispatcher());
	$request=Request::create('GET', '/missing-api');
	$t->same(null, $dispatcher->invoke('authorizeApiRoute', ['api'=>[]], $request));
	$unavailable=$dispatcher->invoke('executeApiRoute', ['api'=>['execution'=>[]]], $request);
	$t->same(503, $unavailable->status);
	$t->same('no-store', $unavailable->headers['Cache-Control'] ?? null);
	$t->hasPathValues([
		'ok'=>false,
		'error'=>'API framework is unavailable.',
	], $t->decodeJson($unavailable->body));
	$exception=$t->throws(
		static fn()=>$app->dispatcher()->dispatch(Request::create('GET', '/missing-routing')),
		RuntimeException::class
	);
	$t->contains('requires the routing compiled route dispatcher', $exception->getMessage());
})->tag('mvc', 'dispatcher', 'deep-coverage')->group('framework-coverage');
