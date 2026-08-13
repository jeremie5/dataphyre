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

if(!function_exists('tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace { function tracelog(mixed ...$arguments): void {} }');
}
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { function tracelog(mixed ...$arguments): void {} }');
}
if(!defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')){
	define('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST',true);
}

suite('Flightdeck Panel and Reactor surfaces')
	->framework(['async','panel','reactor'], ['functions'=>['tracelog']])
	->tag('flightdeck','surface','panel','reactor','coverage')
	->group('framework-coverage')
	->contract('flightdeck.surface.framework-inspectors',1)
	->layer('unit')
	->risk('high')
	->watches('module:flightdeck','module:panel','module:reactor')
	->through('asset routing','framework loading','summary projection','table rendering')
	->isolation('process');

require_once dirname(__DIR__).'/kernel/surfaces/panel.php';
require_once dirname(__DIR__).'/kernel/surfaces/reactor.php';

test('Panel surface turns retained lifecycle state into bounded readable diagnostics',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_panel_surface::class);
	$known=dataphyre_flightdeck_panel_surface::asset_content('nested/panel-surface.css');
	$t->hasPathValues(['content_type'=>'text/css; charset=UTF-8'],$known);
	$t->contains('.fd-panel-context',$known['body']);
	$t->same(null,dataphyre_flightdeck_panel_surface::asset_content('missing.css'));
	$t->same('missing',dataphyre_flightdeck_panel_surface::asset_version('missing.css'));
	$t->contains('/dataphyre/flightdeck/assets/panel-surface.css?v=',dataphyre_flightdeck_panel_surface::asset_url('../panel-surface.css'));
	$t->same('',$surface->invoke('asset_name','bad name.css'));

	$summary=$surface->invoke('summary_cards',[
		'count'=>3,
		'events'=>['resource.registered'=>2,'action.executed'=>1],
		'latest'=>[['event'=>'resource.registered'],['event'=>'action.executed']],
	],[
		'resources'=>[['name'=>'orders']],
		'pages'=>[['name'=>'dashboard']],
		'global_searchable_resources'=>['orders'],
		'navigation'=>[['resource'=>'orders']],
	]);
	$t->containsAll(['Trace Events','Resources','action.executed','resource.registered'],$summary);
	$t->contains('Trace Events',$surface->invoke('summary_cards',[],[]));

	$events=$surface->invoke('events_table',[
		['time'=>1700000000.125,'event'=>'action.executed','context'=>['resource'=>'orders'],'memory'=>1572864],
		['time'=>0,'context'=>'invalid','memory'=>2048],
	]);
	$t->containsAll(['action.executed','orders','1.5 MB','2 KB','none'],$events);
	$resources=$surface->invoke('resources_table',[
		'invalid',
		[
			'name'=>'orders','label'=>'Orders','repository'=>'OrderRepository',
			'form'=>['fields'=>[['name'=>'id']]],
			'table_schema'=>['columns'=>[['name'=>'id']],'views'=>[['name'=>'open']],'summaries'=>[['name'=>'total']]],
			'navigation_badge_lazy'=>true,'global_searchable'=>true,
			'actions'=>[['name'=>'approve']],'relations'=>[['name'=>'customer']],
		],
		['name'=>'customers','model'=>'Customer','navigation_badge'=>7],
	]);
	$t->containsAll(['orders','OrderRepository','lazy','yes','customers','Customer'],$resources);
	$pages=$surface->invoke('pages_table',[
		'invalid',
		['name'=>'dashboard','label'=>'Dashboard','route'=>'/dashboard','group'=>'main','icon'=>'home','navigation_badge_lazy'=>true,'renders'=>true,'authorizes'=>true],
		['name'=>'reports','navigation_badge'=>'4'],
	]);
	$t->containsAll(['dashboard','/dashboard','lazy','reports'],$pages);

	$t->same([
		'scalar'=>'ready',
		'null'=>'null',
		'resource'=>'resource orders / save',
		'valid'=>'valid form',
		'invalid'=>'invalid form',
		'typed'=>'batch(4)',
		'array'=>'array(2)',
		'object'=>'stdClass',
	],$surface->invokeCases([
		'scalar'=>['method'=>'value_label','arguments'=>['ready']],
		'null'=>['method'=>'value_label','arguments'=>[null]],
		'resource'=>['method'=>'value_label','arguments'=>[['resource'=>'orders','operation'=>'save']]],
		'valid'=>['method'=>'value_label','arguments'=>[['valid'=>true]]],
		'invalid'=>['method'=>'value_label','arguments'=>[['valid'=>false]]],
		'typed'=>['method'=>'value_label','arguments'=>[['type'=>'batch','count'=>4]]],
		'array'=>['method'=>'value_label','arguments'=>[[1,2]]],
		'object'=>['method'=>'value_label','arguments'=>[new stdClass()]],
	]));
	$t->contains('none',$surface->invoke('context_summary',[]));
	$t->contains('+2 more',$surface->invoke('context_summary',array_combine(range('a','j'),range(1,10))));
	$t->same('none',$surface->invoke('latest_label',[]));
	$t->same('event',$surface->invoke('latest_label',[[]]));
	$t->same(['bytes'=>'0 B','kilobytes'=>'1 KB','megabytes'=>'1 MB'],$surface->invokeCases([
		'bytes'=>['method'=>'format_bytes','arguments'=>[0]],
		'kilobytes'=>['method'=>'format_bytes','arguments'=>[1024]],
		'megabytes'=>['method'=>'format_bytes','arguments'=>[1048576]],
	]));

	$surface->invoke('load_panel');
	$t->isTrue(class_exists('Dataphyre\\Panel\\Panel'));
	$surface->invoke('load_panel');
	$page=$t->captureOutput(static fn()=>dataphyre_flightdeck_panel_surface::dispatch())->output();
	$t->containsAll(['Panel Resource Inspector','Panel Lifecycle Trace','Registered Resources'],$page);
});

test('Reactor surface explains component capabilities bindings and lifecycle state',static function(Context $t): void {
	$surface=$t->nonPublic(dataphyre_flightdeck_reactor_surface::class);
	$known=dataphyre_flightdeck_reactor_surface::asset_content('reactor-surface.css');
	$t->contains('.fd-reactor-context',$known['body']);
	$t->same(null,dataphyre_flightdeck_reactor_surface::asset_content('missing.css'));
	$t->same('missing',dataphyre_flightdeck_reactor_surface::asset_version('missing.css'));
	$t->contains('/dataphyre/flightdeck/assets/reactor-surface.css?v=',dataphyre_flightdeck_reactor_surface::asset_url('../reactor-surface.css'));
	$t->same('',$surface->invoke('asset_name','bad name.css'));

	$manifest=[
		'version'=>'1.0',
		'components'=>[
			['name'=>'orders','capabilities'=>['state','actions'],'state_keys'=>['status'],'locked'=>[],'actions'=>['approve'],'computed'=>['total'],'rules'=>['valid'],'listeners'=>['saved'],'bindings'=>['text'=>['status'],'click'=>['approve']]],
			['name'=>'customers','capabilities'=>['state']],
		],
		'trace'=>['count'=>2,'active_spans'=>[['name'=>'render']],'latest'=>[['event'=>'component.rendered']],'events'=>['component.rendered'=>2]],
	];
	$summary=$surface->invoke('summary_cards',$manifest);
	$t->containsAll(['1.0','Components','Capabilities','component.rendered'],$summary);
	$t->contains('unknown',$surface->invoke('summary_cards',[]));
	$components=$surface->invoke('components_table',['invalid',...$manifest['components']]);
	$t->containsAll(['orders','customers','actions','click'],$components);
	$events=$surface->invoke('events_table',[
		['time'=>1700000000.125,'event'=>'component.rendered','context'=>['component'=>'orders'],'memory'=>1048576],
		['time'=>0,'context'=>'invalid','memory'=>1024],
	]);
	$t->containsAll(['component.rendered','orders','1 MB','1 KB','none'],$events);
	$t->same([
		'empty'=>'<span class="fd-muted">none</span>',
		'non-array'=>'0',
		'array'=>'2',
		'empty-bindings'=>'<span class="fd-muted">none</span>',
	],$surface->invokeCases([
		'empty'=>['method'=>'list_badges','arguments'=>[[]]],
		'non-array'=>['method'=>'count_label','arguments'=>['invalid']],
		'array'=>['method'=>'count_label','arguments'=>[[1,2]]],
		'empty-bindings'=>['method'=>'binding_label','arguments'=>[[]]],
	]));
	$t->containsAll(['state','actions'],$surface->invoke('list_badges',['state','actions']));
	$t->containsAll(['text','2','invalid','0'],$surface->invoke('binding_label',['text'=>['a','b'],'invalid'=>'value']));
	$t->contains('none',$surface->invoke('context_summary',[]));
	$t->contains('+2 more',$surface->invoke('context_summary',array_combine(range('a','j'),range(1,10))));
	$t->same(['scalar'=>'ready','null'=>'null','array'=>'array(2)','object'=>'stdClass'],$surface->invokeCases([
		'scalar'=>['method'=>'value_label','arguments'=>['ready']],
		'null'=>['method'=>'value_label','arguments'=>[null]],
		'array'=>['method'=>'value_label','arguments'=>[[1,2]]],
		'object'=>['method'=>'value_label','arguments'=>[new stdClass()]],
	]));
	$t->same('none',$surface->invoke('latest_label',[]));
	$t->same('event',$surface->invoke('latest_label',[[]]));
	$t->same(['bytes'=>'0 B','kilobytes'=>'1 KB','megabytes'=>'1 MB'],$surface->invokeCases([
		'bytes'=>['method'=>'format_bytes','arguments'=>[0]],
		'kilobytes'=>['method'=>'format_bytes','arguments'=>[1024]],
		'megabytes'=>['method'=>'format_bytes','arguments'=>[1048576]],
	]));

	$surface->invoke('load_reactor');
	$t->isTrue(class_exists('Dataphyre\\Reactor\\Reactor'));
	$surface->invoke('load_reactor');
	$page=$t->captureOutput(static fn()=>dataphyre_flightdeck_reactor_surface::dispatch())->output();
	$t->containsAll(['Reactor Inspector','Reactive Components','Lifecycle Trace'],$page);
});

test('Surface loaders explain unavailable core-loaded bootstrap and repeated-request states',static function(Context $t): void {
	$root=dirname(__DIR__,4);
	$fixtures=__DIR__.'/fixtures';
	$facades=$fixtures.'/flightdeck_surface_facade_probe.php';
	$surfaces=[
		'panel'=>dirname(__DIR__).'/kernel/surfaces/panel.php',
		'reactor'=>dirname(__DIR__).'/kernel/surfaces/reactor.php',
	];
	foreach($surfaces as $module=>$surface){
		$unavailable=$t->processSucceeded($t->coveredPhpFixture(
			$fixtures.'/flightdeck_surface_unavailable_probe.php',
			[$surface,$t->tempDirectory('flightdeck-'.$module.'-missing-runtime'),$module],
			working_directory:$root,
			framework_root:$root,
		))->json();
		$t->hasPathValues(['status'=>503,'unavailable'=>true,'repeated_dispatches'=>2],$unavailable);

		$coreLoaded=$t->processSucceeded($t->coveredPhpFixture(
			$fixtures.'/flightdeck_surface_core_loader_probe.php',
			[$surface,$facades,$root.'/runtime/',$module],
			working_directory:$root,
			framework_root:$root,
		))->json();
		$t->hasPathValues(['loaded_module'=>$module,'rendered'=>true],$coreLoaded);

		$workspace=$t->workspace('flightdeck-'.$module.'-bootstrap');
		$workspace->file(
			'modules/'.$module.'/Framework/Bootstrap.php',
			'<?php $GLOBALS["dp_flightdeck_surface_bootstrap"]=true; require_once '.var_export($facades,true).';',
		);
		$bootstrapped=$t->processSucceeded($t->coveredPhpFixture(
			$fixtures.'/flightdeck_surface_bootstrap_probe.php',
			[$surface,$workspace->root().DIRECTORY_SEPARATOR,$module],
			working_directory:$root,
			framework_root:$root,
		))->json();
		$t->hasPathValues(['bootstrap_loaded'=>true,'rendered'=>true],$bootstrapped);
	}

	$reactorWithoutRoot=$t->processSucceeded($t->coveredPhpFixture(
		$fixtures.'/flightdeck_surface_unavailable_probe.php',
		[$surfaces['reactor'],'-','reactor'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues(['status'=>503,'unavailable'=>true,'repeated_dispatches'=>2],$reactorWithoutRoot);

	$reactorWithoutTrace=$t->processSucceeded($t->coveredPhpFixture(
		$fixtures.'/flightdeck_surface_core_loader_probe.php',
		[$surfaces['reactor'],$fixtures.'/flightdeck_reactor_facade_without_trace_probe.php',$root.'/runtime/','reactor'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues(['loaded_module'=>'reactor','rendered'=>true],$reactorWithoutTrace);
});
