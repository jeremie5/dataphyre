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

if(!defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')){
	define('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST',true);
}

require_once __DIR__.'/fixtures/flightdeck_debugbar_global_probes.php';
require_once __DIR__.'/fixtures/flightdeck_debugbar_runtime_probes.php';
require_once __DIR__.'/fixtures/flightdeck_view_templating_facade_probe.php';
require_once dirname(__DIR__).'/kernel/surfaces/tracelog.php';

suite('Flightdeck Tracelog surface exact behavior')
	->tag('flightdeck','tracelog','surface','runtime','coverage')
	->group('framework-coverage')
	->contract('flightdeck.tracelog.surface-exact',1)
	->layer('integration')
	->risk('high')
	->watches('module:flightdeck','module:tracelog')
	->through('trace precedence','handoff recovery','plot consumption','runtime metrics','asset boundary')
	->isolation('process');

test('viewer explains fresh retained and absent traces from deterministic runtime observations',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_tracelog_surface::class);
	$server=$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/dataphyre/tracelog/first%20segment']);
	$t->same(['first segment'],$surface->invoke('segments'));
	$server->put('REQUEST_URI','/outside/tracelog');
	$t->same(['outside','tracelog'],$surface->invoke('segments'));
	$server->put('REQUEST_URI','/dataphyre/tracelog');
	$t->globalMap('_GET')->replace(['handoff'=>'runtime-token']);
	$session=$t->globalMap('_SESSION')->replace([
		'tracelog'=>'session trace',
		'flightdeck_last_tracelog'=>'retained trace',
		'tracelog_plotting'=>true,
		'runtime_memory_used'=>32,
		'memory_used'=>8192,
		'memory_used_peak'=>16384,
		'exec_time'=>0.125,
		'included_files'=>12,
		'db_cache'=>['orders'=>['one']],
		'defined_user_function_count'=>7,
		'tracelog_sloc'=>1234,
		'tracelog_code_size'=>'64 KB',
	]);
	$fresh=$t->captureOutput(static fn()=>$surface->invoke('render_viewer',[
		'opcache_status'=>['jit'=>['buffer_size'=>4096,'enabled'=>true]],
		'load_average'=>[1.25,0.5,0.25],
		'handoff_trace'=>'fresh handoff trace',
		'plotter_available'=>true,
	]));
	$t->containsAll([
		'Runtime Metrics','fresh handoff trace','Fresh trace captured','Open Plotter','JIT Enabled','Yes',
	],$fresh->output());
	$t->same('removed',$session->get('tracelog','removed'));
	$t->same('removed',$session->get('tracelog_plotting','removed'));

	$t->globalMap('_GET')->replace([]);
	$session->replace(['flightdeck_last_tracelog'=>'retained trace']);
	$retained=$t->captureOutput(static fn()=>$surface->invoke('render_viewer',[
		'opcache_status'=>false,'load_average'=>false,'handoff_trace'=>'','handoff_directory'=>'',
		'plotter_available'=>false,
	]));
	$t->containsAll(['retained trace','Showing the last retained trace','JIT Enabled','No'],$retained->output());

	$session->replace([]);
	$empty=$t->captureOutput(static fn()=>$surface->invoke('render_viewer',[
		'opcache_status'=>[],'load_average'=>[],'handoff_trace'=>'','handoff_directory'=>'',
		'plotter_available'=>false,
	]));
	$t->contains('Load a page with tracing enabled',$empty->output());

	\dataphyre\tracelog::handoffTrace('class-provided handoff');
	$t->defer(static function(): void { \dataphyre\tracelog::handoffTrace(''); });
	$classHandoff=$t->captureOutput(static fn()=>$surface->invoke('render_viewer',[
		'opcache_status'=>false,'load_average'=>false,'plotter_available'=>false,
	]));
	$t->contains('class-provided handoff',$classHandoff->output());
	\dataphyre\tracelog::handoffTrace('');
});

test('handoff recovery validates signed tokens and falls back to the newest trace file',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_tracelog_surface::class);
	$workspace=$t->workspace('flightdeck-tracelog-handoffs');
	$directory=$workspace->directory('handoff');
	$hash=str_repeat('a',40);
	$token=$hash.'.'.str_repeat('b',64);
	$signed=$workspace->file('handoff/'.$hash.'.dat','signed handoff');
	$t->same('signed handoff',$surface->invoke('read_handoff_trace',$token,$directory));
	unlink($signed);

	$older=$workspace->file('handoff/older.dat','older trace');
	$newer=$workspace->file('handoff/newer.dat','newer trace');
	touch($older,time()-20);
	touch($newer,time()-5);
	$t->same('newer trace',$surface->invoke('read_handoff_trace','invalid-token',$directory));
	$t->same('',$surface->invoke('read_handoff_trace','invalid-token',$workspace->path('missing')));
	$t->same('',$surface->invoke('read_handoff_trace','invalid-token',$workspace->directory('empty')));

	$t->same('/framework/cache/tracelog_handoff',$surface->invoke('handoff_directory',['dataphyre'=>'/framework/']));
	$t->same('/common/cache/tracelog_handoff',$surface->invoke('handoff_directory',['common_dataphyre'=>'/common/']));
	$t->same('',$surface->invoke('handoff_directory',[]));
});

test('plotter consumes bounded newline JSON and exposes stable assets and formatting',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_tracelog_surface::class);
	$workspace=$t->workspace('flightdeck-tracelog-plotter');
	$plotter=$workspace->file('plotter.dat',implode("\n",[
		'{invalid',
		json_encode([['file'=>'first.php','line'=>10,'function'=>'first']],JSON_THROW_ON_ERROR),
		json_encode([['file'=>'second.php','line'=>20,'function'=>'second']],JSON_THROW_ON_ERROR),
	]));
	$page=$t->captureOutput(static fn()=>$surface->invoke('render_plotter',$plotter,1))->output();
	$t->containsAll(['fd-tracelog-plotter','first.php','1 trace frames consumed'],$page);
	$t->isFalse(is_file($plotter));
	$empty=$t->captureOutput(static fn()=>$surface->invoke('render_plotter',$workspace->path('missing.dat')))->output();
	$t->contains('No plotting data is available',$empty);
	$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/dataphyre/tracelog/plotter']);
	$t->contains('No plotting data is available',$t->captureOutput(
		static fn()=>dataphyre_flightdeck_tracelog_surface::dispatch(),
	)->output());

	$available=$workspace->file('available.dat','[]');
	$t->isTrue($surface->invoke('plotter_available',$available,false));
	$t->isTrue($surface->invoke('plotter_available',$workspace->path('missing.dat'),true));
	$t->isFalse($surface->invoke('plotter_available',$workspace->path('missing.dat'),false));
	$t->same('/framework/cache/tracelog_plotting.dat',$surface->invoke('plotter_file',['dataphyre'=>'/framework/']));
	$t->same('/common/cache/tracelog_plotting.dat',$surface->invoke('plotter_file',['common_dataphyre'=>'/common/']));
	$t->same('',$surface->invoke('plotter_file',[]));

	$t->same([
		'zero'=>'0 b','kilobyte'=>'1 kb','petabyte_cap'=>'1024 pb','label'=>'already formatted',
	],$surface->invokeCases([
		'zero'=>['method'=>'storage','arguments'=>[-1]],
		'kilobyte'=>['method'=>'storage','arguments'=>[1024]],
		'petabyte_cap'=>['method'=>'storage','arguments'=>[pow(1024,6)]],
		'label'=>['method'=>'storage','arguments'=>['already formatted']],
	]));

	$metrics=$surface->invoke('render_runtime_metrics',[['Execution','1.000s']],[['CPU Load','0.5%']],true);
	$t->containsAll(['Execution','1.000s','CPU Load','Open Plotter'],$metrics);
	$t->notContains('Open Plotter',$surface->invoke('render_runtime_metrics',[],[],false));

	$t->contains('/dataphyre/flightdeck/assets/tracelog-surface.css?v=',dataphyre_flightdeck_tracelog_surface::asset_url('../tracelog-surface.css'));
	$t->matches('/^[a-f0-9]{16}$/',dataphyre_flightdeck_tracelog_surface::asset_version('tracelog-surface.css'));
	$t->same('missing',dataphyre_flightdeck_tracelog_surface::asset_version('missing.asset'));
	$t->contains('.fd-runtime-metrics',dataphyre_flightdeck_tracelog_surface::asset_content('tracelog-surface.css')['body']);
	$t->contains('dataphyreTracelogData',dataphyre_flightdeck_tracelog_surface::asset_content('tracelog-plotter.js')['body']);
	$t->isNull(dataphyre_flightdeck_tracelog_surface::asset_content('bad asset'));
	$t->same('raw javascript',$surface->invoke('script_body','raw javascript'));

	include dirname(__DIR__).'/kernel/surfaces/tracelog.php';
});

test('surface file auto-dispatches and redispatches when included by a real route',static function(Context $t): void {
	$root=dirname(__DIR__,4);
	$payload=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/flightdeck_tracelog_surface_entrypoint_probe.php',
		[$root,dirname(__DIR__).'/kernel/surfaces/tracelog.php'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues(['pages'=>2,'viewer'=>true],$payload);
});
