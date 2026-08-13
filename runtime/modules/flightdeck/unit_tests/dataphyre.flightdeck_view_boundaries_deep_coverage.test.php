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

suite('Flightdeck view boundaries')
	->tag('flightdeck','view','bootstrap','coverage')
	->group('framework-coverage')
	->contract('flightdeck.view.bootstrap-boundaries',1)
	->layer('integration')
	->risk('high')
	->watches('module:flightdeck','module:templating')
	->through('asset cache','capture cleanup','navigation filtering','bootstrap loading')
	->isolation('process');

require_once dirname(__DIR__).'/kernel/auth.php';
require_once dirname(__DIR__).'/kernel/view.php';

test('view primitives cache known assets clean failed captures and hide unavailable navigation',static function(Context $t): void {
	$view=$t->nonPublic(dataphyre_flightdeck_view::class);
	$t->same('missing',dataphyre_flightdeck_view::asset_version('missing.css'));
	$t->same(null,dataphyre_flightdeck_view::asset_content('missing.css'));
	$first=dataphyre_flightdeck_view::asset_content('flightdeck.css');
	$second=dataphyre_flightdeck_view::asset_content('flightdeck.css');
	$t->same($first,$second);
	$t->same(dataphyre_flightdeck_view::asset_version('flightdeck.css'),dataphyre_flightdeck_view::asset_version('flightdeck.css'));

	$t->same('captured',dataphyre_flightdeck_view::capture(static function(): void { echo 'captured'; }));
	$level=ob_get_level();
	$t->throws(static fn()=>dataphyre_flightdeck_view::capture(static function(): never {
		echo 'discarded';
		throw new RuntimeException('capture failed');
	}),RuntimeException::class);
	$t->same($level,ob_get_level());

	$view->invoke('load_core_helpers');
	$t->isFalse($view->invoke('module_installed',''));
	$t->isFalse($view->invoke('module_installed','bad/module'));
	$t->isFalse($view->invoke('nav_item_available',['surface'=>'missing.php']));
	$t->isFalse($view->invoke('nav_item_available',['module'=>'missing-module']));
	$t->isTrue($view->invoke('nav_item_available',[]));
	$t->greaterThanOrEqual(2,count($view->invoke('module_roots')));
	$t->isFalse($view->invoke('surface_available',''));

	$t->global('dataphyre_flightdeck_config')->replace(['enabled'=>true,'password'=>'secret']);
	$t->contains('Logout',$view->invoke('sidebar_bottom',[]));
	$t->same('',$view->invoke('sidebar_bottom',['logout'=>false]));
	include dirname(__DIR__).'/kernel/view.php';
});

test('view bootstrap reports missing templating and accepts an explicitly discovered facade',static function(Context $t): void {
	$root=dirname(__DIR__,4);
	$fixture=__DIR__.'/fixtures/flightdeck_view_bootstrap_probe.php';
	$viewFile=dirname(__DIR__).'/kernel/view.php';
	$missing=$t->processSucceeded($t->coveredPhpFixture(
		$fixture,[$viewFile,'missing',__DIR__.'/fixtures'],working_directory:$root,framework_root:$root,
	))->json();
	$t->hasPathValues(['rendered'=>false,'exception'=>RuntimeException::class],$missing);
	$t->contains('requires the Dataphyre templating module',$missing['message']);

	$candidate=$t->processSucceeded($t->coveredPhpFixture(
		$fixture,[$viewFile,'candidate',__DIR__.'/fixtures'],working_directory:$root,framework_root:$root,
	))->json();
	$t->hasPathValues(['rendered'=>true,'contracts'=>2],$candidate);
});
