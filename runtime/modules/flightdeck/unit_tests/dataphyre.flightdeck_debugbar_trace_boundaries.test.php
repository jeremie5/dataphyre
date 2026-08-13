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

require_once __DIR__.'/fixtures/flightdeck_trace_runtime_probe.php';
require_once dirname(__DIR__).'/kernel/debugbar/support.php';
require_once dirname(__DIR__).'/kernel/debugbar/trace.php';

final class DpFlightdeckDebugbarTraceHarness {
	private const TRACE_TIGHT_RENDER_LIMIT=80;
	use dataphyre_flightdeck_debugbar_support;
	use dataphyre_flightdeck_debugbar_trace;

	private static function metric(string $label,string $value,string $hint,string $tone='',string $attributes=''): string {
		return '<span>'.self::e($label).' '.self::e($value).' '.self::e($hint).'</span>';
	}
}

final class DpFlightdeckTraceUnavailableStream {
	public mixed $context=null;
	public function url_stat(string $path,int $flags): array { return ['mode'=>0100444,2=>0100444]; }
	public function stream_open(string $path,string $mode,int $options,?string &$opened_path): bool { return false; }
}

suite('Flightdeck debugbar Tracelog boundaries')
	->tag('flightdeck','debugbar','tracelog','coverage')
	->group('framework-coverage')
	->contract('flightdeck.debugbar.trace-boundaries',1)
	->layer('integration')
	->risk('high')
	->watches('module:flightdeck','module:tracelog')
	->through('runtime buffers','session handoff','plot cache','graph normalization','tight-memory rendering')
	->isolation('process');

test('Tracelog state tolerates unavailable live buffers and retains session fallback',static function(Context $t): void {
	$trace=$t->nonPublic(DpFlightdeckDebugbarTraceHarness::class);
	$t->global('retroactive_tracelog')->replace([
		['/app/Order.php',42,'OrderService','load','loaded','info',[],1000.1,2048],
		null,
	]);

	if(session_status()!==PHP_SESSION_ACTIVE){
		@session_start();
	}
	$t->same(PHP_SESSION_ACTIVE,session_status());
	$t->globalMap('_SESSION')->replace([
		'flightdeck_last_tracelog'=>'Order.php: 42 : retained session row',
		'flightdeck_last_tracelog_handoff'=>'handoff-1',
	]);
	$t->defer(static function(): void {
		if(session_status()===PHP_SESSION_ACTIVE){
			session_write_close();
		}
	});

	$uninitialized=$trace->invoke('trace_state');
	$t->hasPathValues(['live_bytes'=>0,'retroactive_count'=>2],$uninitialized);
	\dataphyre\tracelog::$tracelog='';
	$state=$trace->invoke('trace_state');
	$t->hasPathValues([
		'live_bytes'=>0,
		'session_entry_count'=>1,
		'handoff'=>'handoff-1',
	],$state);
});

test('Tracelog plotting reads bounded cache frames and normalizes malformed graph input',static function(Context $t): void {
	$trace=$t->nonPublic(DpFlightdeckDebugbarTraceHarness::class);
	$t->same('',$trace->invoke('trace_plot_file',[]));
	$t->same('/workspace/common/cache/tracelog_plotting.dat',$trace->invoke('trace_plot_file',[
		'common_dataphyre'=>'/workspace/common',
	]));

	$t->streamWrapper('fdtracefail',DpFlightdeckTraceUnavailableStream::class);
	$t->same([],$trace->invoke('trace_plot_frames_from_file','fdtracefail://plot.dat'));

	$workspace=$t->workspace('flightdeck-trace-plot');
	$frame=json_encode([[
		'file'=>'/app/Order.php','line'=>42,'class'=>'OrderService','function'=>'load','args'=>[42],'time'=>'1',
	]],JSON_THROW_ON_ERROR);
	$plotFile=$workspace->file('cache/tracelog_plotting.dat',"\n".str_repeat($frame."\n",601)."not-json\n");
	$frames=$trace->invoke('trace_plot_frames_from_file',$plotFile);
	$t->count(599,$frames);
	$t->hasPathValues([
		'source'=>'plotting_file','frame_count'=>599,'node_count'=>1,
	],$trace->invoke('trace_plot_state',[],$frames));

	$frameGraph=$trace->invoke('trace_plot_from_frames',[
		'invalid',
		[],
		[
			['file'=>'/app/Empty.php','line'=>1,'function'=>''],
			['file'=>'/app/Same.php','line'=>2,'function'=>'same'],
			['file'=>'/app/Same.php','line'=>2,'function'=>'same'],
		],
	],'boundary_frames');
	$t->hasPathValues(['frame_count'=>1,'node_count'=>1,'link_count'=>0],$frameGraph);
	$flatGraph=$trace->invoke('trace_plot_from_entries',[null,[],['call'=>''],['call'=>'run']],'boundary_rows');
	$t->hasPathValues(['frame_count'=>1,'node_count'=>1],$flatGraph);
	$t->same('',$trace->invoke('trace_plot_node','node',['function'=>'run','class'=>'N/A'],0)['class']);
});

test('Tracelog parsing and rendering make fallback behavior explicit',static function(Context $t): void {
	$trace=$t->nonPublic(DpFlightdeckDebugbarTraceHarness::class);
	$entries=$trace->invoke('live_trace_entries',"<br><span></span><br><b>Header</b> message<br>");
	$t->count(1,$entries);
	$t->same('message',$entries[0]['message']);
	$t->contains('Failure',$trace->invoke('trace_message_html','<i>trace</i> > <b><span style="color:red">Failure</span></b>'));

	$resource=fopen('php://memory','rb');
	$t->same('N/A',$trace->invoke('trace_parameter_shape_value',$resource));
	fclose($resource);
	$t->same('',$trace->invoke('trace_parameter_shape_from_message',''));
	$t->globalMap('_SERVER')->replace([]);
	$t->same(null,$trace->invoke('trace_offset_ms',1000.0));

	$retained=['origin'=>'session','type'=>'info','message'=>'retained'];
	$t->contains('Retained session tracelog',$trace->invoke('render_tracelog_panel',[
		'entry_count'=>1,'entries'=>[],'live_entries'=>[],'session_entries'=>[$retained],
	]));

	$node=$trace->invoke('trace_plot_node','node-a',[
		'file'=>'/app/Order.php','line'=>42,'function'=>'load','class'=>'OrderService',
	],0);
	$node['count']=2;
	$plotHtml=$trace->invoke('render_trace_plot',[
		'nodes'=>[null,$node],
		'links'=>[null,['source'=>'missing','target'=>'node-a','count'=>1]],
	]);
	$t->contains('dfd-trace-plot-svg',$plotHtml);

	$t->phpIni(['memory_limit'=>'32M']);
	$many=array_fill(0,82,$retained);
	$many[81]='invalid';
	$t->contains('earlier rows omitted',$trace->invoke('render_trace_log','Tight trace',$many));
	$t->contains(date('H:i:s',1000),$trace->invoke('render_trace_line',[
		'timestamp'=>1000,'type'=>'warning','message'=>'timestamp fallback',
	]));

	include dirname(__DIR__).'/kernel/debugbar/trace.php';
});
