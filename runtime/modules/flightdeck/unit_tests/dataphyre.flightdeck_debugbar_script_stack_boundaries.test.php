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

require_once dirname(__DIR__).'/kernel/debugbar.php';

suite('Flightdeck debugbar script and stack boundaries')
	->tag('flightdeck','debugbar','javascript','stack','coverage')
	->group('framework-coverage')
	->contract('flightdeck.debugbar.script-stack-boundaries',1)
	->layer('unit')
	->risk('medium')
	->watches('module:flightdeck')
	->through('probe credentials','JSON safety','frame normalization','trait guards')
	->isolation('process');

test('client probe generation fails closed for absent or non-encodable credentials',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$t->same([
		'missing_snapshot'=>'',
		'missing_token'=>'',
		'invalid_utf8'=>'',
	],$debugbar->invokeCases([
		'missing_snapshot'=>['method'=>'client_probe_script','arguments'=>['','token']],
		'missing_token'=>['method'=>'client_probe_script','arguments'=>['snapshot','']],
		'invalid_utf8'=>['method'=>'client_probe_script','arguments'=>["\xB1",'token']],
	]));

	include dirname(__DIR__).'/kernel/debugbar/scripts.php';
});

test('stack normalization rejects malformed frames and enforces its retention bounds',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$t->same('',$debugbar->invoke('render_error_stack_panel',[]));

	$stack=[];
	$trace=[null,[],['file'=>'/app/MissingLine.php']];
	for($index=1;$index<=13;$index++){
		$frame=[
			'file'=>'/app/Frame'.$index.'.php',
			'line'=>$index,
			'class'=>'App\\Frame'.$index,
			'function'=>'run',
		];
		$stack[]=$frame;
		$trace[]=$frame;
	}

	$t->count(12,$debugbar->invoke('frames_from_stack',$stack));
	$t->count(12,$debugbar->invoke('stack_frames_from_backtrace',$trace));

	include dirname(__DIR__).'/kernel/debugbar/stack.php';
});
