<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use dataphyre\application_definition;
use dataphyre\runtime;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/application_definition.php';
require_once dirname(__DIR__).'/kernel/runtime.php';

test('framework runtime intercepts only an exact claimed and signed scheduler callback',static function(Context $t): void {
	$runtime=$t->nonPublic(runtime::class);
	$definition=new application_definition('scheduler-probe',$t->tempDirectory('scheduler-runtime'));
	$claim=str_repeat('c',64);
	$server=[
		'REQUEST_URI'=>'/dataphyre/scheduler/tenant-beta.lifecycle?ignored=1',
		'REQUEST_METHOD'=>'GET',
		'HTTP_X_TRAFFIC_SOURCE'=>'internal_traffic',
		'HTTP_X_DATAPHYRE_SCHEDULER_CLAIM'=>$claim,
		'HTTP_X_DATAPHYRE_SCHEDULER_KEY'=>'signed-request',
	];
	$t->same('tenant-beta.lifecycle',$runtime->invoke('scheduler_route_name',$server));
	$t->isNull($runtime->invoke('scheduler_route_name',['REQUEST_URI'=>'/dataphyre/scheduler/..']));
	$t->isNull($runtime->invoke('scheduler_route_name',['REQUEST_URI'=>'/dataphyre/scheduler/'.str_repeat('a',129)]));
	$t->isNull($runtime->invoke('scheduler_route_name',['REQUEST_URI'=>'/dataphyre/scheduler/name%2Fother']));

	$verified=[];
	$loaded=[];
	$executed=[];
	$responses=[];
	$handled=$runtime->invoke('boot_internal_runtime_route',$definition,[
		'server'=>$server,
		'verify'=>static function(string $token,string $name,string $candidateClaim)use(&$verified): bool {
			$verified[]=[$token,$name,$candidateClaim];
			return $token==='signed-request' && $name==='tenant-beta.lifecycle';
		},
		'core_loader'=>static fn(): bool=>true,
		'module_loader'=>static function(string $module)use(&$loaded): bool {$loaded[]=$module; return true;},
		'task_runner'=>static function(string $name,string $candidateClaim)use(&$executed): void {$executed[]=[$name,$candidateClaim];},
		'respond'=>static function(int $status,string $body)use(&$responses): void {$responses[]=[$status,$body];},
	]);
	$t->isTrue($handled);
	$t->same([['signed-request','tenant-beta.lifecycle',$claim]],$verified);
	$t->same(['scheduling'],$loaded);
	$t->same([['tenant-beta.lifecycle',$claim]],$executed);
	$t->same([],$responses);

	$missingClaim=$server;
	unset($missingClaim['HTTP_X_DATAPHYRE_SCHEDULER_CLAIM']);
	$t->isTrue($runtime->invoke('boot_internal_runtime_route',$definition,[
		'server'=>$missingClaim,
		'verify'=>static fn(): bool=>true,
		'respond'=>static function(int $status,string $body)use(&$responses): void {$responses[]=[$status,$body];},
	]));
	$post=$server;
	$post['REQUEST_METHOD']='POST';
	$t->isTrue($runtime->invoke('boot_internal_runtime_route',$definition,[
		'server'=>$post,
		'verify'=>static fn(): bool=>true,
		'respond'=>static function(int $status,string $body)use(&$responses): void {$responses[]=[$status,$body];},
	]));
	$t->same([[404,'Not found'],[404,'Not found']],$responses);
	$t->isFalse($runtime->invoke('boot_internal_runtime_route',$definition,[
		'server'=>['REQUEST_URI'=>'/ordinary-route'],
	]));
});
