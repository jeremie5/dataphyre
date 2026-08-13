<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/flightdeck_debugbar_scenarios.php';
require_once dirname(__DIR__).'/kernel/debugbar.php';

test('Flightdeck normalizes memory policy and splices isolated toolbar markup without damaging the document', static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);

	foreach([
		[null,[null, false, 0, -3, 0.0, '', '0', 'false', 'off', 'none', 'null', 'invalid']],
		['1',[1, 1.9, '1']],
		['-1',['-1']],
		['128M',['128M', '128 mb']],
		['1.5G',['1.50 GiB']],
		['1024',['1024']],
	] as [$expected,$configuredValues]){
		foreach($configuredValues as $configuredValue){
			$t->same($expected,$debugbar->invoke('normalize_configured_memory_limit',$configuredValue));
		}
	}

	$t->isFalse($debugbar->invoke('quick_response_allows_toolbar_markup',''));
	$t->isFalse($debugbar->invoke('quick_response_allows_toolbar_markup','plain response'));
	$t->isTrue($debugbar->invoke('quick_response_allows_toolbar_markup','<!doctype html><title>Orders</title>'));
	$t->isTrue($debugbar->invoke('quick_response_allows_toolbar_markup','<html><body>Orders</body></html>'));
	$t->same('',$debugbar->invoke('low_memory_reason','<html><body>Orders</body></html>'));
	$t->global('dataphyre_flightdeck_config')->replace([
		'enabled'=>true,'debugbar'=>['capture_tracelog'=>true,'capture_tracelog_plotting'=>true],
	]);
	$debugbar->invoke('enable_tracelog_capture_when_configured');
	$t->isTrue($t->global('dataphyre_tracelog_capture_retroactive')->value());

	$compact=$debugbar->invoke('low_memory_markup','Only <1 MB remained.');
	$t->contains('id="dataphyre-flightdeck-debugbar-host"',$compact);
	$t->contains('Compact',$compact);
	$markup='<aside id="flightdeck-probe">Toolbar</aside>';
	$t->same(
		'<html><body>Orders'.$markup.'</body></html>',
		$debugbar->invoke('splice_toolbar_markup','<html><body>Orders</body></html>',$markup),
	);
	$t->same('Orders'.$markup,$debugbar->invoke('splice_toolbar_markup','Orders',$markup));
})->tag('flightdeck','coverage','debugbar','memory')->group('framework-coverage');

test('Flightdeck observer buffers stay bounded while preserving upstream PHP error semantics', static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$sqlEvents=$t->global('dataphyre_flightdeck_sql_events')->replace('invalid');

	dataphyre_flightdeck_debugbar::observe_sql(['sequence'=>'first']);
	$t->same([['sequence'=>'first']],$sqlEvents->value());
	$sqlEvents->replace(array_fill(0,239,['sequence'=>'seed']));
	dataphyre_flightdeck_debugbar::observe_sql(['sequence'=>'penultimate']);
	dataphyre_flightdeck_debugbar::observe_sql(['sequence'=>'last']);
	$boundedSqlEvents=$sqlEvents->value();
	$t->count(240,$boundedSqlEvents);
	$t->same('seed',$boundedSqlEvents[0]['sequence']);
	$t->same('last',$boundedSqlEvents[239]['sequence']);

	$phpErrors=$t->global('dataphyre_flightdeck_php_errors')->replace('invalid');
	$debugbar->replacePropertyForTest('previous_error_handler',null);
	$t->isFalse(dataphyre_flightdeck_debugbar::observe_php_error(E_USER_NOTICE,'Notice without a previous handler',__FILE__,__LINE__));
	$t->hasPathValues([
		'errno'=>E_USER_NOTICE,
		'severity'=>'info',
		'message'=>'Notice without a previous handler',
	],$phpErrors->value()[0]);

	$phpErrors->replace(array_fill(0,119,['message'=>'seed']));
	$debugbar->writeProperty('previous_error_handler',static fn(): bool=>true);
	$t->isTrue(dataphyre_flightdeck_debugbar::observe_php_error(E_USER_WARNING,'Handled warning',__FILE__,__LINE__));
	$debugbar->writeProperty('previous_error_handler',static fn(): string=>'handled');
	$t->isTrue(dataphyre_flightdeck_debugbar::observe_php_error(E_USER_WARNING,'Non-boolean handler result',__FILE__,__LINE__));
	$debugbar->writeProperty('previous_error_handler',static function(): never {
		throw new RuntimeException('handler exploded');
	});
	$t->isTrue(dataphyre_flightdeck_debugbar::observe_php_error(E_USER_WARNING,'Failing handler',__FILE__,__LINE__));
	$boundedPhpErrors=$phpErrors->value();
	$t->count(120,$boundedPhpErrors);
	$t->contains('Previous PHP error handler failed: handler exploded',$boundedPhpErrors[119]['message']);
})->tag('flightdeck','coverage','debugbar','observers')->group('framework-coverage');

test('Flightdeck shutdown diagnostics repair matching history and describe unmatched error responses', static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$snapshot=flightdeck_debugbar_rich_snapshot();
	$snapshot['id']='shutdown-match';
	$snapshot['method']='POST';
	$snapshot['uri']='/orders?mode=approve';
	$snapshot['request']['method']='POST';
	$snapshot['request']['path']='/orders';
	$session=$t->globalMap('_SESSION')->replace([
		'dataphyre_flightdeck_debugbar_history'=>[$snapshot],
	]);
	$t->globalMap('_SERVER')->replace([
		'REQUEST_METHOD'=>'POST',
		'REQUEST_URI'=>'/orders?mode=approve',
		'HTTP_HOST'=>'example.test',
	]);
	$t->global('dataphyre_flightdeck_php_errors')->replace([
		['errno'=>E_USER_WARNING,'message'=>'Late warning','file'=>__FILE__,'line'=>__LINE__],
	]);

	$debugbar->invoke('record_shutdown_status',503,true);
	$repaired=$session->getPath(['dataphyre_flightdeck_debugbar_history',0]);
	$t->same(503,$repaired['request']['status']);
	$t->hasPathValues(['observed'=>true,'fatal'=>true,'status'=>503],$repaired['shutdown']);
	$t->same('Late warning',$repaired['errors']['events'][0]['message']);

	$session->clear();
	$t->globalMap('_SERVER')->replace([
		'REQUEST_METHOD'=>'GET',
		'REQUEST_URI'=>'/missing?source=shutdown',
		'HTTP_HOST'=>'example.test',
	]);
	$debugbar->invoke('record_shutdown_status',200,false);
	$t->same([],$session->map());
	$debugbar->invoke('record_shutdown_status',404,false);
	$t->same([],$session->map());
})->tag('flightdeck','coverage','debugbar','shutdown')->group('framework-coverage');
