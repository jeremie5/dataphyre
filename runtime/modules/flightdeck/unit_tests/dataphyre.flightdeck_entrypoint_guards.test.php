<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Flightdeck entrypoint guards')
	->tag('flightdeck','entrypoint','route-guard','module-bootstrap','coverage')
	->group('framework-coverage')
	->contract('flightdeck.entrypoint.guards',1)
	->layer('integration')
	->risk('critical')
	->watches('module:flightdeck')
	->through('module dependency declaration','fail-closed responses','authenticated authorization')
	->isolation('process');

require_once dirname(__DIR__).'/kernel/route_guard.php';

test('module bootstrap declares templating as Flightdeck dependency',static function(Context $t): void {
	$root=dirname(__DIR__,4);
	$payload=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/flightdeck_module_main_probe.php',
		[dirname(__DIR__).'/kernel/flightdeck.main.php'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->same([['flightdeck','templating']],$payload['required_modules']);
});

test('route guard explains every rejected policy and authorizes a signed operator',static function(Context $t): void {
	$guard=$t->nonPublic(dataphyre_flightdeck_route_guard::class);
	$terminated=0;
	$terminator=static function()use(&$terminated): void {$terminated++;};
	$decisions=[
		'installation_missing'=>[false,false,false,false,503,'Flightdeck installation is incomplete.'],
		'production_hidden'=>[true,true,true,true,404,'Not found'],
		'configuration_disabled'=>[true,false,false,true,404,'Flightdeck is disabled.'],
	];
	foreach($decisions as $name=>[$available,$production,$enabled,$authenticated,$status,$message]){
		http_response_code(200);
		$response=$t->captureOutput(static fn()=>$guard->invoke(
			'authorize_state',$available,$production,$enabled,$authenticated,$terminator,
		));
		$t->isFalse($response->result(),$name);
		$t->same($status,http_response_code(),$name);
		$t->same($message,$response->output(),$name);
	}
	$t->same(3,$terminated);

	$t->global('dataphyre_flightdeck_config')->replace([
		'enabled'=>true,
		'password'=>'route-guard-secret',
		'rate_limit'=>['window'=>30,'max_attempts'=>5],
	]);
	$t->globalMap('_SERVER')->replace([
		'REQUEST_URI'=>'/dataphyre/datadoc',
		'HTTP_USER_AGENT'=>'Route guard boundary browser',
	]);
	$t->globalMap('_COOKIE')->replace([]);
	$t->isTrue(dataphyre_flightdeck_auth::login('route-guard-secret'));
	$t->isTrue(dataphyre_flightdeck_route_guard::authorize('datadoc',$terminator));

	$t->globalMap('_COOKIE')->replace([]);
	$redirected=false;
	$t->isFalse($guard->invoke('authorize_state',true,false,true,false,static function()use(&$redirected): void {
		$redirected=true;
	}));
	$t->isTrue($redirected);
	$t->same(302,http_response_code());

	include dirname(__DIR__).'/kernel/route_guard.php';
});
