<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mvc\RouteDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'mvc'=>true],
		'disabled'=>['routing'=>true],
		'core_implicit'=>true,
	]);
}
$dp_mvc_no_dispatcher_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_mvc_no_dispatcher_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_mvc_no_dispatcher_modules_root);
require_once $dp_mvc_no_dispatcher_modules_root.'/routing/Framework/CompilableRoute.php';
require_once $dp_mvc_no_dispatcher_modules_root.'/routing/Framework/ControllerAction.php';
require_once $dp_mvc_no_dispatcher_modules_root.'/routing/Framework/Route.php';
require_once $dp_mvc_no_dispatcher_modules_root.'/mvc/Framework/RouteDefinition.php';

test('mvc route definition returns no match when the compiled dispatcher is unavailable', static function(Context $t): void {
	$route=RouteDefinition::make('GET', '/without-dispatcher', static fn()=>1);
	$parameters=[];
	$t->isFalse(class_exists('dataphyre\\routing\\compiled_route_dispatcher'));
	$t->isFalse($route->matches('GET', '/without-dispatcher', $parameters));
	$t->same([], $parameters);
})->tag('mvc', 'routes', 'coverage')->group('framework-coverage');
