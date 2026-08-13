<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/tracelog_runtime_test_helpers.php';

suite('Tracelog HTTP and viewer surface contract')
	->contract('tracelog.surfaces', 1)
	->layer('integration')
	->risk('medium')
	->watches('module:tracelog')
	->through('asset-cache', 'plot-data', 'filesystem-inventory', 'viewer-rendering')
	->isolation('case')
	->tag('tracelog', 'surfaces', 'exact-coverage')
	->group('framework-coverage');

test('asset endpoint resolves binding query and URI names into immutable cache responses', static function(Context $t): void {
	$missing=dataphyre_tracelog_asset_endpoint::dispatch(['bindings'=>['asset'=>'missing.css']]);
	$t->hasPathValues(['status'=>404,'body'=>'Not found','headers.Cache-Control'=>'no-store'], $missing);
	$viewer=dataphyre_tracelog_asset_endpoint::dispatch([
		'query'=>['asset'=>'viewer.css'],
		'server'=>['REQUEST_METHOD'=>'GET'],
		'modified_at'=>1700000000,
	]);
	$t->same(200, $viewer['status']);
	$t->contains('text/css', $viewer['headers']['Content-Type']);
	$t->same((string)strlen($viewer['body']), $viewer['headers']['Content-Length']);

	$head=dataphyre_tracelog_asset_endpoint::dispatch([
		'server'=>['REQUEST_URI'=>'/dataphyre/tracelog/assets/plotter.js','REQUEST_METHOD'=>'HEAD'],
		'modified_at'=>1700000000,
	]);
	$t->same(200, $head['status']);
	$t->same('', $head['body']);
	$t->isTrue((int)$head['headers']['Content-Length']>0);

	$etag=dataphyre_tracelog_asset_endpoint::dispatch([
		'bindings'=>['asset'=>'viewer.css'],
		'server'=>['HTTP_IF_NONE_MATCH'=>$viewer['headers']['ETag']],
		'modified_at'=>1700000000,
	]);
	$t->same(304, $etag['status']);
	$modified=dataphyre_tracelog_asset_endpoint::dispatch([
		'bindings'=>['asset'=>'viewer.css'],
		'server'=>['HTTP_IF_MODIFIED_SINCE'=>'Wed, 15 Nov 2023 00:00:00 GMT'],
		'modified_at'=>1700000000,
	]);
	$t->same(304, $modified['status']);
	$stale=dataphyre_tracelog_asset_endpoint::dispatch([
		'bindings'=>['asset'=>'viewer.css'],
		'server'=>['HTTP_IF_MODIFIED_SINCE'=>'Mon, 01 Jan 2001 00:00:00 GMT'],
		'modified_at'=>1700000000,
	]);
	$t->same(200, $stale['status']);
});

test('asset bootstrap separates response selection from emission and supports direct output', static function(Context $t): void {
	$t->same(null, dataphyre_tracelog_asset_endpoint::bootstrap(false));
	$emit=$t->spy()->willReturn(null);
	$response=dataphyre_tracelog_asset_endpoint::bootstrap(true, [
		'bindings'=>['asset'=>'viewer.css'],
		'query'=>[],
		'server'=>['REQUEST_METHOD'=>'GET'],
		'emit'=>$emit,
	]);
	$t->same(200, $response['status']);
	$emit->assertCalledTimes($t, 1);
	$t->throws(static fn()=>dataphyre_tracelog_asset_endpoint::bootstrap(true, [
		'bindings'=>['asset'=>'viewer.css'],'query'=>[],'server'=>[],'emit'=>'invalid',
	]), LogicException::class);
	$output=$t->captureOutput(static fn()=>dataphyre_tracelog_asset_endpoint::bootstrap(true, [
		'bindings'=>['asset'=>'viewer.css'],'query'=>[],'server'=>['REQUEST_METHOD'=>'GET'],
	]))->output();
	$t->contains('background-color', $output);
});

test('asset bootstrap accepts route bindings from the loaded router in direct legacy mode', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$payload=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/tracelog_routing_asset_probe.php',
		[dirname(__DIR__).'/kernel/assets.php'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues([
		'status'=>200,
		'emitted_status'=>200,
		'content_type'=>'text/css; charset=UTF-8',
	], $payload);
});

test('plotter consumes valid bounded JSON lines once and explains absent or malformed data', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	$t->contains('No plotting data available', dataphyre_tracelog_plotter_page::fromFile($scenario->path('missing.dat')));
	$path=$scenario->file('plotting.dat', implode(PHP_EOL, [
		'not-json',
		'[]',
		'[{"file":"a.php","line":1,"function":"one","time":"0.1"}]',
		'[{"file":"b.php","line":2,"function":"two","time":"0.2"}]',
	]));
	$html=dataphyre_tracelog_plotter_page::fromFile($path, ['limit'=>1]);
	$t->contains('window.tracelogData=', $html);
	$t->contains('a.php', $html);
	$t->isFalse(str_contains($html, 'b.php'));
	$t->isFalse(is_file($path));

	$empty=$scenario->file('empty.dat', 'invalid');
	$t->contains('No plotting data available', dataphyre_tracelog_plotter_page::fromFile($empty));
	$t->isTrue(is_file($empty));
	$readerFailure=$scenario->file('reader-failure.dat', 'ignored');
	$t->contains('No plotting data available', dataphyre_tracelog_plotter_page::fromFile($readerFailure, [
		'read'=>static fn(): bool=>false,
	]));
	$t->throws(static fn()=>dataphyre_tracelog_plotter_page::fromFile('/unused', [
		'exists'=>'invalid','read'=>'invalid','remove'=>'invalid',
	]), LogicException::class);
	$t->throws(static fn()=>dataphyre_tracelog_plotter_page::render(dp_tracelog_unencodable_plot_fixture()), RuntimeException::class);
});

test('plotter bootstrap renders managed files and remains inert when embedded', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	$t->same('', dataphyre_tracelog_plotter_page::bootstrap(false));
	$path=$scenario->file('bootstrap-plotting.dat', '[{"file":"boot.php","line":4,"function":"boot","time":"0.4"}]');
	$html=dataphyre_tracelog_plotter_page::bootstrap(true, ['path'=>$path,'memory_limit'=>'256M']);
	$t->contains('boot.php', $html);
	$t->contains('plotter.css?v=', $html);
});

test('viewer file inventory excludes volatile folders and caches line and byte totals', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	$php=$scenario->file('source/app.php', "<?php\nreturn true;\n");
	$text=$scenario->file('source/readme.txt', 'docs');
	$scenario->file('source/cache/ignored.php', "<?php\n");
	$scenario->file('source/logs/ignored.php', "<?php\n");
	$scenario->directory('source/nested');
	$t->same([], iterator_to_array(project_files($scenario->path('missing'))));
	$t->same([$php], array_values(iterator_to_array(project_files($scenario->path('source'), 'PHP'))));
	$t->isTrue(in_array($text, array_values(iterator_to_array(project_files($scenario->path('source')))), true));

	$state=[];
	$t->same(2, lines_of_code($state, [$php,$scenario->path('missing.php')], $scenario->path('source')));
	$t->same(2, lines_of_code($state, [], $scenario->path('source')));
	$calculatedSize=code_size($state, [$php,$text], $scenario->path('source'));
	$t->type('string', $calculatedSize);
	$t->same($calculatedSize, code_size($state, [], $scenario->path('source')));
	$globalState=null;
	$t->same(0, lines_of_code($globalState, [], $scenario->path('source')));
	$globalSize=null;
	$t->same('0 b', code_size($globalSize, [], $scenario->path('source')));
});

test('viewer storage units and rendering expose runtime evidence with and without traces', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	$t->same('unchanged', convert_storage('unchanged'));
	$t->same('0 b', convert_storage(0));
	$t->same('0 b', convert_storage(-10));
	$t->same('1 kb', convert_storage(1024));
	$t->same('1024 pb', convert_storage(1024 ** 6));
	$source=$scenario->file('viewer/source.php', "<?php\n// line two\n");
	$session=[
		'tracelog'=>'<br><b>trace body</b>',
		'tracelog_plotting'=>true,
		'runtime_memory_used'=>10,
		'memory_used'=>1000,
		'memory_used_peak'=>2000,
		'exec_time'=>0.125,
		'defined_user_function_count'=>4,
		'included_files'=>8,
		'db_cache'=>['one'=>'cached'],
	];
	$html=dataphyre_tracelog_viewer_page::render($session, [
		'jit'=>['buffer_size'=>1024,'enabled'=>true,'opt_level'=>5],
		'load_average'=>[1.23456],
		'php_version'=>'8.test',
		'project_root'=>$scenario->path('viewer'),
		'source_files'=>[$source],
		'all_files'=>[$source],
	]);
	$t->contains('CPU Usage: 1.235%', $html);
	$t->contains('PHP: 8.test', $html);
	$t->contains('Project PHP SLOC: 2', $html);
	$t->contains('JIT Enabled: Yes', $html);
	$t->contains('View plotter', $html);
	$t->contains('<br><b>trace body</b>', $html);

	$empty=dataphyre_tracelog_viewer_page::render([], [
		'jit'=>[], 'load_average'=>[], 'project_root'=>$scenario->path('viewer'),
		'source_files'=>[], 'all_files'=>[],
	]);
	$t->contains('JIT Buffer Size: N/A', $empty);
	$t->contains('Load a page and refresh', $empty);
});

test('viewer bootstrap supports explicit snapshots and consumes global trace state only in direct mode', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	$t->same('', dataphyre_tracelog_viewer_page::bootstrap(false));
	$explicit=dataphyre_tracelog_viewer_page::bootstrap(true, [
		'session'=>['tracelog'=>'explicit trace'],
		'project_root'=>$scenario->path(),
		'source_files'=>[], 'all_files'=>[], 'jit'=>[], 'load_average'=>[],
	]);
	$t->contains('explicit trace', $explicit);
	$global=dataphyre_tracelog_viewer_page::bootstrap(true, [
		'project_root'=>$scenario->path(), 'source_files'=>[], 'all_files'=>[], 'jit'=>[], 'load_average'=>[],
	]);
	$t->contains('Tracelog Viewer', $global);
});
