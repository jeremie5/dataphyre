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

require_once __DIR__.'/fixtures/flightdeck_debugbar_global_probes.php';
require_once __DIR__.'/fixtures/flightdeck_debugbar_runtime_probes.php';
require_once dirname(__DIR__).'/kernel/debugbar.php';

suite('Flightdeck debugbar runtime boundaries')
	->tag('flightdeck','debugbar','runtime','shutdown','memory','coverage')
	->group('framework-coverage')
	->contract('flightdeck.debugbar.runtime-boundaries',1)
	->layer('integration')
	->risk('high')
	->watches('module:flightdeck')
	->through('startup observers','memory policy','shutdown repair','authorized injection')
	->isolation('process');

test('startup attaches runtime observers once and applies every memory-policy decision',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$config=$t->global('dataphyre_flightdeck_config');
	$server=$t->globalMap('_SERVER')->replace([
		'REQUEST_URI'=>'/dataphyre/debugbar','REQUEST_METHOD'=>'GET','HTTP_USER_AGENT'=>'Runtime boundary browser',
	]);
	$active=$t->global('dataphyre_flightdeck_debugbar_active');
	$active->unsetValue();
	$config->replace(['enabled'=>true,'debugbar'=>['enabled'=>true,'capture_tracelog'=>false]]);
	dataphyre_flightdeck_debugbar::start_request();
	$t->isFalse($active->exists());
	$debugbar->invoke('enable_tracelog_capture_when_configured');

	$server->put('REQUEST_URI','/orders');
	$config->replace(['enabled'=>true,'debugbar'=>[
		'enabled'=>true,'capture_tracelog'=>true,'capture_tracelog_plotting'=>true,
	]]);
	$debugbar->replacePropertyForTest('sql_observer_attached',false);
	$debugbar->replacePropertyForTest('error_observer_attached',false);
	$debugbar->replacePropertyForTest('shutdown_observer_attached',false);
	$debugbar->replacePropertyForTest('response_status_guard_attached',false);
	$t->defer(static function(): void { restore_error_handler(); });
	dataphyre_flightdeck_debugbar::start_request();
	dataphyre_flightdeck_debugbar::start_request();
	$t->isTrue($active->value());
	$t->same(1,\dataphyre\sql::observerCount());
	$t->isTrue(\dataphyre\tracelog::$enable);
	$t->isTrue(\dataphyre\tracelog::$plotting);
	$t->isTrue($t->global('dataphyre_tracelog_capture_retroactive')->value());
	\dataphyre\tracelog::failWhileSettingPlotting(true);
	$t->defer(static function(): void { \dataphyre\tracelog::failWhileSettingPlotting(false); });
	$debugbar->invoke('enable_tracelog_capture_when_configured');
	\dataphyre\tracelog::failWhileSettingPlotting(false);
	$t->isTrue(\dataphyre\tracelog::$enable);

	$memoryState=$t->global('dataphyre_flightdeck_debugbar_memory_limit');
	$tracelogCalls=$t->global('flightdeck_debugbar_tracelog_calls')->replace([]);
	$t->isNull($debugbar->invoke('normalize_configured_memory_limit','not-a-memory-limit'));
	$t->hasPathValues([
		4=>'Invalid Flightdeck debugbar memory_limit value: not-a-memory-limit',
		5=>'warning',
	],$tracelogCalls->value()[0]);
	$config->replace(['enabled'=>false,'debugbar'=>['memory_limit'=>'128M']]);
	dataphyre_flightdeck_debugbar::apply_configured_memory_limit();
	$config->replace(['enabled'=>true,'debugbar'=>['enabled'=>false,'memory_limit'=>'128M']]);
	dataphyre_flightdeck_debugbar::apply_configured_memory_limit();
	$config->replace(['enabled'=>true,'debugbar'=>['enabled'=>true,'memory_limit'=>null]]);
	dataphyre_flightdeck_debugbar::apply_configured_memory_limit();

	$t->phpIni(['memory_limit'=>'-1']);
	$config->replace(['enabled'=>true,'debugbar'=>['enabled'=>true,'memory_limit'=>'128M']]);
	dataphyre_flightdeck_debugbar::apply_configured_memory_limit();
	$t->hasPathValues(['configured'=>'128M','applied'=>false],$memoryState->value());

	$t->phpIni(['memory_limit'=>'1G']);
	dataphyre_flightdeck_debugbar::apply_configured_memory_limit();
	$t->hasPathValues(['configured'=>'128M','applied'=>false],$memoryState->value());

	$t->phpIni(['memory_limit'=>'128M']);
	$config->replace(['enabled'=>true,'debugbar'=>['enabled'=>true,'memory_limit'=>'256M']]);
	dataphyre_flightdeck_debugbar::apply_configured_memory_limit();
	$t->hasPathValues(['configured'=>'256M','effective'=>'256M','applied'=>true],$memoryState->value());

	$oldStatus=http_response_code() ?: 200;
	$t->defer(static function()use($oldStatus): void { http_response_code($oldStatus); });
	$restored=$t->global('dataphyre_flightdeck_debugbar_status_restored');
	$restored->clear();
	$debugbar->replacePropertyForTest('response_status_guard_pass',0);
	$debugbar->replacePropertyForTest('injection_response_status',0);
	dataphyre_flightdeck_debugbar::finalize_response_status_guard();
	dataphyre_flightdeck_debugbar::finalize_response_status_guard();
	$debugbar->invoke('finalize_response_status_guard_state',0,503,null,false);
	$debugbar->invoke('finalize_response_status_guard_state',200,200,null,false);
	$debugbar->invoke('finalize_response_status_guard_state',200,503,['type'=>E_ERROR],false);
	$debugbar->invoke('finalize_response_status_guard_state',200,503,null,true);
	http_response_code(503);
	$debugbar->invoke('finalize_response_status_guard_state',200,503,null,false);
	$t->same(200,http_response_code());
	$t->hasPathValues(['from'=>503,'to'=>200],$restored->value());
});

test('shutdown diagnostics preserve matching history and stop cleanly when memory is exhausted',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$t->globalMap('_SERVER')->replace(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/orders','HTTP_HOST'=>'example.test']);
	$errors=$t->global('dataphyre_flightdeck_php_errors')->replace([]);
	$session=$t->globalMap('_SESSION')->replace([
		'dataphyre_flightdeck_debugbar_history'=>[
			['id'=>'unrelated','method'=>'GET','uri'=>'/customers','request'=>['method'=>'GET','path'=>'/customers','status'=>200]],
			['id'=>'matching','method'=>'GET','uri'=>'/orders','request'=>['method'=>'GET','path'=>'/orders','status'=>200]],
		],
	]);

	$debugbar->invoke('observe_shutdown_state',[
		'type'=>E_ERROR,'message'=>'Fatal shutdown probe','file'=>__FILE__,'line'=>__LINE__,
	],200,false);
	$t->contains('Fatal shutdown probe',$errors->value()[0]['message']);
	$debugbar->invoke('observe_shutdown_state',null,200,false);
	$debugbar->invoke('observe_shutdown_state',null,200,true);
	$debugbar->invoke('observe_shutdown_state',null,404,true);
	$debugbar->invoke('observe_shutdown_state',null,503,true,static function(): void {
		throw new RuntimeException('Deterministic shutdown-history failure.');
	});
	$t->same(404,$session->getPath(['dataphyre_flightdeck_debugbar_history',1,'request','status']));
	$t->hasPathValues([
		'observed'=>true,'fatal'=>false,'status'=>404,
	],$session->getPath(['dataphyre_flightdeck_debugbar_history',1,'shutdown']));
	dataphyre_flightdeck_debugbar::observe_shutdown();

	$sqlEvents=$t->global('dataphyre_flightdeck_sql_events')->replace([]);
	$errorCount=count($errors->value());
	$history=$session->get('dataphyre_flightdeck_debugbar_history');
	$t->phpIni(['memory_limit'=>(string)(memory_get_usage(true)+1048576)]);
	dataphyre_flightdeck_debugbar::observe_sql(['event'=>'memory-boundary']);
	$debugbar->invoke('record_php_error',E_USER_WARNING,'memory-boundary',__FILE__,__LINE__);
	$debugbar->invoke('record_shutdown_status',503,true);
	$t->same([],$sqlEvents->value());
	$t->count($errorCount,$errors->value());
	$t->same($history,$session->get('dataphyre_flightdeck_debugbar_history'));
});

test('authorized injection rejects unsafe responses and degrades deterministically under memory pressure',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$workspace=$t->workspace('flightdeck-debugbar-runtime-auth');
	$t->environment(['DATAPHYRE_FLIGHTDECK_CACHE_DIR'=>$workspace->path('cache')]);
	$config=$t->global('dataphyre_flightdeck_config')->replace([
		'enabled'=>true,'password'=>'runtime-secret','rate_limit'=>['window'=>30,'max_attempts'=>5],
		'debugbar'=>['enabled'=>true,'memory_limit'=>null],
	]);
	$server=$t->globalMap('_SERVER')->replace([
		'REQUEST_URI'=>'/orders','REQUEST_METHOD'=>'GET','HTTP_USER_AGENT'=>'Runtime boundary browser','HTTPS'=>'on',
	]);
	$cookie=$t->globalMap('_COOKIE')->replace([]);
	$html='<html><body>Orders</body></html>';

	$t->isFalse(dataphyre_flightdeck_debugbar::enabled());
	dataphyre_flightdeck_debugbar::enable();
	$t->isFalse($cookie->exists() && $cookie->get('dataphyre_flightdeck_debugbar')!==null);
	$t->isTrue(dataphyre_flightdeck_auth::login('runtime-secret'));
	dataphyre_flightdeck_debugbar::enable();
	$t->isTrue(dataphyre_flightdeck_debugbar::enabled());

	$t->same([
		'html'=>true,'json'=>false,'javascript'=>false,'css'=>false,
		'image'=>false,'font'=>false,'audio'=>false,'video'=>false,
	],$debugbar->invokeCases([
		'html'=>['method'=>'quick_response_allows_toolbar_markup','arguments'=>['plain',['Content-Type: text/html']]],
		'json'=>['method'=>'quick_response_allows_toolbar_markup','arguments'=>[$html,['Content-Type: application/json']]],
		'javascript'=>['method'=>'quick_response_allows_toolbar_markup','arguments'=>[$html,['Content-Type: application/javascript']]],
		'css'=>['method'=>'quick_response_allows_toolbar_markup','arguments'=>[$html,['Content-Type: text/css']]],
		'image'=>['method'=>'quick_response_allows_toolbar_markup','arguments'=>[$html,['Content-Type: image/png']]],
		'font'=>['method'=>'quick_response_allows_toolbar_markup','arguments'=>[$html,['Content-Type: font/woff2']]],
		'audio'=>['method'=>'quick_response_allows_toolbar_markup','arguments'=>[$html,['Content-Type: audio/mpeg']]],
		'video'=>['method'=>'quick_response_allows_toolbar_markup','arguments'=>[$html,['Content-Type: video/mp4']]],
	]));

	$memoryReasons=$debugbar->invokeCases([
		'small_limit'=>['method'=>'low_memory_reason','arguments'=>[$html,['limit'=>16777216]]],
		'tight_remaining'=>['method'=>'low_memory_reason','arguments'=>[$html,[
			'limit'=>33554432,'remaining'=>1048576,'tight'=>true,'tight_headroom'=>false,
		]]],
		'full_headroom'=>['method'=>'low_memory_reason','arguments'=>[$html,[
			'limit'=>67108864,'remaining'=>1048576,'tight'=>false,'full_headroom'=>false,
		]]],
		'safe'=>['method'=>'low_memory_reason','arguments'=>[$html,[
			'limit'=>67108864,'remaining'=>33554432,'tight'=>false,'full_headroom'=>true,
		]]],
	]);
	$t->pathsContain([
		'small_limit'=>'memory_limit is 16mb',
		'tight_remaining'=>'Only 1mb remained',
		'full_headroom'=>'Not enough memory remained',
	],$memoryReasons);
	$t->same('',$memoryReasons['safe']);
	$t->same($html,$debugbar->invoke('splice_toolbar_markup',$html,'<aside>probe</aside>',false));

	dataphyre_flightdeck_debugbar::disable();
	$t->same($html,dataphyre_flightdeck_debugbar::inject($html));
	$t->same($html,$debugbar->invoke('inject_response',$html));
	dataphyre_flightdeck_debugbar::enable();
	$server->put('REQUEST_URI','/dataphyre/debugbar');
	$t->same($html,$debugbar->invoke('inject_response',$html));
	$server->put('REQUEST_URI','/orders');
	$duplicate='<html><body><div id="dataphyre-flightdeck-debugbar">existing</div></body></html>';
	$t->same($duplicate,$debugbar->invoke('inject_response',$duplicate));
	$t->same('plain response',$debugbar->invoke('inject_response','plain response'));

	$compact=$debugbar->invoke('inject_response',$html,[
		'limit'=>16777216,'splice_headroom'=>true,
	]);
	$t->contains('Compact',$compact);
	$jsonWithHtmlMarker='{"markup":"<html>"}';
	$t->same($jsonWithHtmlMarker,$debugbar->invoke('inject_response',$jsonWithHtmlMarker,[
		'limit'=>67108864,'remaining'=>33554432,'tight'=>false,'full_headroom'=>true,
	]));
	$t->same($jsonWithHtmlMarker,$debugbar->invoke('inject_response',$jsonWithHtmlMarker,[
		'limit'=>67108864,'remaining'=>33554432,'tight'=>false,'full_headroom'=>true,
		'record_headroom'=>false,
	]));
	$postCaptureCompact=$debugbar->invoke('inject_response',$html,[
		'limit'=>67108864,'remaining'=>33554432,'tight'=>false,'full_headroom'=>true,
		'post_capture_headroom'=>false,'splice_headroom'=>true,
	]);
	$t->contains('switched to compact mode',$postCaptureCompact);
	$full=$debugbar->invoke('inject_response',$html,[
		'limit'=>67108864,'remaining'=>33554432,'tight'=>false,'full_headroom'=>true,
		'splice_headroom'=>true,
	]);
	$t->contains('dataphyre-flightdeck-debugbar-host',$full);
});

test('debugbar bootstrap fails closed when authentication is unavailable',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$html='<html><body>Orders</body></html>';
	$t->isFalse(dataphyre_flightdeck_debugbar::enabled(false));
	dataphyre_flightdeck_debugbar::enable(false);
	dataphyre_flightdeck_debugbar::apply_configured_memory_limit(false);
	$debugbar->invoke('enable_tracelog_capture_when_configured',false);
	$t->same($html,$debugbar->invoke('inject_response',$html,['auth_available'=>false]));
	include dirname(__DIR__).'/kernel/debugbar.php';
});
