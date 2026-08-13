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

if(!defined('DATAPHYRE_FLIGHTDECK_ASSET_ENDPOINT_NO_DISPATCH')){
	define('DATAPHYRE_FLIGHTDECK_ASSET_ENDPOINT_NO_DISPATCH',true);
}

require_once __DIR__.'/fixtures/flightdeck_debugbar_global_probes.php';
require_once dirname(__DIR__).'/kernel/debugbar_assets.php';
require_once dirname(__DIR__).'/kernel/flightdeck_assets.php';

suite('Flightdeck asset endpoints')
	->tag('flightdeck','assets','http','cache','coverage')
	->group('framework-coverage')
	->contract('flightdeck.assets.http-boundary',1)
	->layer('integration')
	->risk('high')
	->watches('module:flightdeck')
	->through('request resolution','immutable validators','HEAD requests','surface assets','fail-closed policy')
	->isolation('process');

test('shared asset responses describe GET HEAD validators and missing resources',static function(Context $t): void {
	$source=dirname(__DIR__).'/kernel/asset_response.php';
	$content=['content_type'=>'text/css; charset=UTF-8','body'=>'.probe{display:block}'];
	$t->same('bound.css',dataphyre_flightdeck_asset_response::request_asset(
		['asset'=>'bound.css'],['asset'=>'query.css'],['REQUEST_URI'=>'/uri.css'],
	));
	$t->same('query.css',dataphyre_flightdeck_asset_response::request_asset(
		[],['asset'=>'query.css'],['REQUEST_URI'=>'/uri.css'],
	));
	$t->same('uri.css',dataphyre_flightdeck_asset_response::request_asset(
		[],[],['REQUEST_URI'=>'/assets/uri.css?version=1'],
	));

	$get=dataphyre_flightdeck_asset_response::build('probe.css',$content,$source,['REQUEST_METHOD'=>'GET']);
	$t->hasPathValues(['status'=>200,'body'=>'.probe{display:block}','asset'=>'probe.css'],$get);
	$t->containsAll([
		'Content-Type: text/css; charset=UTF-8',
		'Cache-Control: public, max-age=31536000, immutable',
		'Content-Length: 21',
	],$get['headers']);
	$t->same(['Pragma','Expires'],$get['remove_headers']);

	$head=dataphyre_flightdeck_asset_response::build('probe.css',$content,$source,['REQUEST_METHOD'=>'HEAD']);
	$t->hasPathValues(['status'=>200,'body'=>'','etag'=>$get['etag']],$head);
	$etagMatch=dataphyre_flightdeck_asset_response::build('probe.css',$content,$source,[
		'HTTP_IF_NONE_MATCH'=>$get['etag'],
	]);
	$t->hasPathValues(['status'=>304,'body'=>''],$etagMatch);
	$modifiedMatch=dataphyre_flightdeck_asset_response::build('probe.css',$content,$source,[
		'HTTP_IF_MODIFIED_SINCE'=>'Fri, 01 Jan 2100 00:00:00 GMT',
	]);
	$t->hasPathValues(['status'=>304,'body'=>''],$modifiedMatch);
	$t->hasPathValues([
		'status'=>404,'body'=>'Not found','asset'=>'','etag'=>'','last_modified'=>'',
	],dataphyre_flightdeck_asset_response::build('missing.css',null,$source));
	$t->hasPathValues([
		'status'=>200,'body'=>'',
	],dataphyre_flightdeck_asset_response::build('empty.bin',[],$t->tempDirectory('missing-source').'/none.php'));

	$emitted=$t->captureOutput(static fn()=>dataphyre_flightdeck_asset_response::emit(
		dataphyre_flightdeck_asset_response::missing(),
	));
	$t->same('Not found',$emitted->output());
	$t->same(404,http_response_code());
	include dirname(__DIR__).'/kernel/asset_response.php';
});

test('Debugbar assets fail closed and serve embedded CSS through the shared responder',static function(Context $t): void {
	$debugbarFile=dirname(__DIR__).'/kernel/debugbar.php';
	$missing=$t->captureOutput(static fn()=>dataphyre_flightdeck_debugbar_assets_endpoint::dispatch(
		$t->tempDirectory('missing-debugbar').'/debugbar.php',false,[],[],['REQUEST_METHOD'=>'GET'],
	));
	$t->same('Not found',$missing->output());
	$t->same(404,http_response_code());

	$hidden=$t->captureOutput(static fn()=>dataphyre_flightdeck_debugbar_assets_endpoint::dispatch(
		$debugbarFile,true,[],[],['REQUEST_METHOD'=>'GET'],
	));
	$t->same('Not found',$hidden->output());
	$t->same(404,http_response_code());

	$css=$t->captureOutput(static fn()=>dataphyre_flightdeck_debugbar_assets_endpoint::dispatch(
		$debugbarFile,false,['asset'=>'debugbar.css'],[],['REQUEST_METHOD'=>'GET'],
	));
	$t->contains('#dataphyre-flightdeck-debugbar',$css->output());
	$t->same(200,http_response_code());

	$unknown=$t->captureOutput(static fn()=>dataphyre_flightdeck_debugbar_assets_endpoint::dispatch(
		$debugbarFile,false,[],['asset'=>'unknown.asset'],['REQUEST_METHOD'=>'GET'],
	));
	$t->same('Not found',$unknown->output());
	$t->same(404,http_response_code());
	include dirname(__DIR__).'/kernel/debugbar_assets.php';
});

test('Debugbar endpoint fails closed when a valid dependency file declares no runtime class',static function(Context $t): void {
	$emptyDependency=$t->workspace('empty-debugbar-dependency')->file('debugbar.php','<?php');
	$response=$t->captureOutput(static fn()=>dataphyre_flightdeck_debugbar_assets_endpoint::dispatch(
		$emptyDependency,false,['asset'=>'debugbar.css'],[],['REQUEST_METHOD'=>'GET'],
	));
	$t->same('Not found',$response->output());
	$t->same(404,http_response_code());
});

test('console endpoint resolves shell logs and every module-owned surface asset',static function(Context $t): void {
	$viewFile=dirname(__DIR__).'/kernel/view.php';
	$flightdeckFile=dirname(__DIR__).'/kernel/flightdeck.php';
	$authFile=dirname(__DIR__).'/kernel/auth.php';
	$missingView=$t->captureOutput(static fn()=>dataphyre_flightdeck_assets_endpoint::dispatch(
		$t->tempDirectory('missing-view').'/view.php',$flightdeckFile,$authFile,false,[],[],['REQUEST_METHOD'=>'GET'],
	));
	$t->same('Not found',$missingView->output());
	$t->same(404,http_response_code());

	$hidden=$t->captureOutput(static fn()=>dataphyre_flightdeck_assets_endpoint::dispatch(
		$viewFile,$flightdeckFile,$authFile,true,[],[],['REQUEST_METHOD'=>'GET'],
	));
	$t->same('Not found',$hidden->output());
	$t->same(404,http_response_code());

	$assetCases=[
		'console_shell'=>['flightdeck.css',':root{--bg'],
		'live_logs'=>['flightdeck-logs.css','.fd-log'],
		'panel_inspector'=>['panel-surface.css','.fd-panel'],
		'reactor_inspector'=>['reactor-surface.css','.fd-reactor'],
		'diagnostic_panel'=>['dpanel-surface.css','.fd-dpanel'],
		'documentation_workspace'=>['datadoc-surface.css','.fd-datadoc'],
		'trace_viewer'=>['tracelog-surface.css','.fd-runtime-metrics'],
		'trace_plotter'=>['tracelog-plotter.js','dataphyreTracelogData'],
	];
	$responses=[];
	$markers=[];
	foreach($assetCases as $name=>[$asset,$marker]){
		$markers[$name]=$marker;
		$responses[$name]=$t->captureOutput(static fn()=>dataphyre_flightdeck_assets_endpoint::dispatch(
			$viewFile,$flightdeckFile,$authFile,false,['asset'=>$asset],[],['REQUEST_METHOD'=>'GET'],
		))->output();
	}
	$t->pathsContain($markers,$responses);

	$unknown=$t->captureOutput(static fn()=>dataphyre_flightdeck_assets_endpoint::dispatch(
		$viewFile,$flightdeckFile,$authFile,false,[],[],[
			'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/dataphyre/flightdeck/assets/unknown.asset',
		],
	));
	$t->same('Not found',$unknown->output());
	$t->same(404,http_response_code());

	$endpoint=$t->nonPublic(dataphyre_flightdeck_assets_endpoint::class);
	$t->isNull($endpoint->invoke(
		'surface_asset_content','panel-surface.css',$t->tempDirectory('missing-flightdeck-surfaces'),
	));
	$t->isNull($endpoint->invoke(
		'surface_asset_content','panel-surface.css',dirname(__DIR__).'/kernel/surfaces',[],
	));
	include dirname(__DIR__).'/kernel/flightdeck_assets.php';
});

test('real asset entrypoint files auto-dispatch through their public HTTP boundary',static function(Context $t): void {
	$root=dirname(__DIR__,4);
	$fixture=__DIR__.'/fixtures/flightdeck_asset_entrypoint_probe.php';
	$entrypoints=[
		'debugbar'=>[dirname(__DIR__).'/kernel/debugbar_assets.php','debugbar.css'],
		'console'=>[dirname(__DIR__).'/kernel/flightdeck_assets.php','flightdeck.css'],
	];
	foreach($entrypoints as $name=>[$entrypoint,$asset]){
		$payload=$t->processSucceeded($t->coveredPhpFixture(
			$fixture,[$root,$entrypoint,$asset],working_directory:$root,framework_root:$root,
		))->json();
		$t->same(200,$payload['status'],$name);
		$t->greaterThan(100,$payload['body_length'],$name);
		$t->matches('/^[a-f0-9]{40}$/',$payload['body_hash'],$name);
	}
});
