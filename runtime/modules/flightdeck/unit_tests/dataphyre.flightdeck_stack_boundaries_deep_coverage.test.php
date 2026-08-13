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

require_once __DIR__.'/fixtures/flightdeck_stack_sql_probe.php';
require_once __DIR__.'/fixtures/flightdeck_stack_highlighter_probes.php';
require_once __DIR__.'/fixtures/flightdeck_stack_routing_probe.php';
require_once dirname(__DIR__).'/kernel/stack_snippets.php';

final class DpFlightdeckUnreadableSourceStream {
	public mixed $context=null;
	public function url_stat(string $path,int $flags): array { return ['mode'=>0100444,2=>0100444]; }
	public function stream_open(string $path,string $mode,int $options,?string &$opened_path): bool { return false; }
}

suite('Flightdeck stack boundary diagnostics')
	->tag('flightdeck','stack','datadoc','coverage')
	->group('framework-coverage')
	->contract('flightdeck.stack.read-only-boundaries',1)
	->layer('integration')
	->risk('high')
	->watches('module:flightdeck','module:datadoc')
	->through('source failures','highlighter adapters','indexed context','URL resolution')
	->isolation('process');

test('stack snippets keep unreadable source and alternate highlighters non-fatal',static function(Context $t): void {
	$stack=$t->nonPublic(dataphyre_flightdeck_stack_snippets::class);
	$t->same([],dataphyre_flightdeck_stack_snippets::frames_from_log_entry("#0 /tmp/probe.php(0): run()"));
	$t->same('',dataphyre_flightdeck_stack_snippets::render_diagnostics(null));

	$t->streamWrapper('fdunreadable',DpFlightdeckUnreadableSourceStream::class);
	$unreadable=dataphyre_flightdeck_stack_snippets::render_snippet([
		'index'=>1,'file'=>'fdunreadable://probe.php','line'=>1,'kind'=>'callsite',
	]);
	$t->contains('Source unreadable',$unreadable);

	$workspace=$t->workspace('flightdeck-stack-boundaries');
	$source=$workspace->file('Probe.php',"<?php\n  function probe(){\n \t\$value='ready';\n  probe;\n  }\n");
	$includeItems=$stack->invoke(
		'include_path_items',
		"require({$source}): Failed to open stream",
		[['file'=>$source,'line'=>1]],
	);
	$t->same('ok',$includeItems[0]['tone']);
	$t->contains('exists on disk',$includeItems[0]['summary']);
	$fallback=dataphyre_flightdeck_stack_snippets::render_snippet([
		'index'=>0,'file'=>$source,'line'=>3,'kind'=>'callsite','symbol'=>'probe',
	],[],[
		'use_datadoc_context'=>false,
		'highlighter_file'=>$workspace->path('missing-highlighter.php'),
		'class_suffix'=>'fd-probe-suffix',
	]);
	$t->containsAll(['fd-probe-suffix','fd-callsite','ready'],$fallback);

	$emptyHighlighter=$workspace->file('empty-highlighter.php','<?php');
	$t->same(null,$stack->invoke('datadoc_highlight','<?php',1,1,[],[
		'highlighter_file'=>$emptyHighlighter,'highlighter_class'=>'DpMissingHighlighter',
	]));
	$t->same(null,$stack->invoke('datadoc_highlight','<?php',1,1,[],[
		'highlighter_file'=>__DIR__.'/fixtures/flightdeck_stack_highlighter_probes.php',
		'highlighter_class'=>DpFlightdeckStackThrowingHighlighter::class,
	]));
	$highlighted=$stack->invoke('datadoc_highlight','<?php echo 1;',1,1,['project'=>'demo'],[
		'highlighter_file'=>__DIR__.'/fixtures/flightdeck_stack_highlighter_probes.php',
		'highlighter_class'=>DpFlightdeckStackReadableHighlighter::class,
	]);
	$t->containsAll(['data-project="demo"','data-language="php"'],$highlighted);

	$t->same('',$stack->invoke('call_argument_expression',$source,4,'probe',1));
	$t->contains('value',$stack->invoke('variables_near_frame',$source,3));
	$noVariables=$workspace->file('NoVariables.php',"<?php\nreturn 1;\n");
	$t->same([],$stack->invoke('variables_near_frame',$noVariables,2));
	$t->same(["\tone",' two'],$stack->invoke('normalize_snippet_lines',[" \tone",'  two']));
	$routeBinding=$stack->invoke('analyze_expression_value',"\\dataphyre\\routing::\$bindings['order']");
	$t->same('route bindings unavailable or not array',$routeBinding['rows']['Runtime value']);
	include dirname(__DIR__).'/kernel/stack_snippets.php';
});

test('stack DataDoc context chooses the most specific project and nearest indexed symbol',static function(Context $t): void {
	$stack=$t->nonPublic(dataphyre_flightdeck_stack_snippets::class);
	$workspace=$t->workspace('flightdeck-stack-datadoc');
	$source=$workspace->file('app/src/OrderService.php',"<?php\nfunction approve(){}\n");
	$empty=['project'=>'','namespace'=>'','class'=>'','function'=>'','datadoc_url'=>null,'project_url'=>null];

	DpFlightdeckStackSqlProbe::respond(['datadoc.projects'=>['malformed']]);
	$t->same($empty,$stack->invoke('datadoc_frame_context',$source,2));
	DpFlightdeckStackSqlProbe::respond(['datadoc.projects'=>[[
		['path'=>'','name'=>''],
		['path'=>$workspace->path('elsewhere'),'name'=>'elsewhere'],
	]]]);
	$t->same($empty,$stack->invoke('datadoc_frame_context',$source,2));

	$projects=[
		['path'=>$workspace->root(),'name'=>'workspace'],
		['path'=>$workspace->path('app'),'name'=>'application'],
	];
	DpFlightdeckStackSqlProbe::respond([
		'datadoc.projects'=>[$projects],
		'dataphyre.datadoc_data'=>['malformed'],
	]);
	$projectOnly=$stack->invoke('datadoc_frame_context',$source,2);
	$t->hasPathValues(['project'=>'application','function'=>''],$projectOnly);
	$t->contains('/dataphyre/datadoc/application',$projectOnly['project_url']);

	DpFlightdeckStackSqlProbe::respond([
		'datadoc.projects'=>[$projects],
		'dataphyre.datadoc_data'=>[[-1,['line'=>0],
			['line'=>1,'namespace'=>'Shop','class'=>'OrderService','function'=>'approve','type'=>'function','content'=>'approve'],
			['line'=>20,'namespace'=>'Future','function'=>'later','type'=>'function','content'=>'later'],
		]],
	]);
	$indexed=$stack->invoke('datadoc_frame_context',$source,2);
	$t->hasPathValues([
		'project'=>'application','namespace'=>'Shop','class'=>'OrderService','function'=>'approve',
	],$indexed);
	$t->containsAll(['dynadoc?','function=approve'],$indexed['datadoc_url']);

	DpFlightdeckStackSqlProbe::fail(new RuntimeException('database unavailable'));
	$t->same($empty,$stack->invoke('datadoc_frame_context',$source,2));
});

test('stack DataDoc URLs honor the active core base URL when one exists',static function(Context $t): void {
	$root=dirname(__DIR__,4);
	$result=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/flightdeck_stack_core_url_probe.php',
		[$root,dirname(__DIR__).'/kernel/stack_snippets.php'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->same('https://console.example.test/app/dataphyre/datadoc',$result['url']);
});
